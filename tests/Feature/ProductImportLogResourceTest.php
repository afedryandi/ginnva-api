<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductImportLogResource;
use App\Filament\Resources\ProductImportLogResource\Pages\ListProductImportLogs;
use App\Filament\Resources\ProductImportLogResource\Pages\ViewProductImportLog;
use App\Models\ProductImportLog;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Riwayat Impor Produk: log append-only tiap "Import Excel" di Daftar Produk -- hanya untuk full-access, hanya lihat
 * (tanpa tambah / ubah / hapus), terbaru dulu, dengan rincian error per baris di halaman detail.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class ProductImportLogResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('direksi', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $menuAccess = null, array $extra = []): User
    {
        $store = Store::firstOrCreate(['name' => 'Toko Test'], ['city' => 'Jakarta', 'address' => 'Jl. A', 'is_active' => true]);

        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function log(string $filename, string $at, ?User $by = null, int $total = 10, int $updated = 8, int $skipped = 2, ?array $errors = null): ProductImportLog
    {
        $log = ProductImportLog::create(['user_id' => $by?->id, 'filename' => $filename, 'total_rows' => $total, 'updated_count' => $updated, 'skipped_count' => $skipped, 'errors' => $errors]);
        DB::table('product_import_logs')->where('id', $log->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $log->fresh();
    }

    public function test_only_full_access_accounts_can_open_the_history(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(ProductImportLogResource::canViewAny());

        $this->actingAs($this->user('direksi'), 'web');
        $this->assertTrue(ProductImportLogResource::canViewAny());

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertFalse(ProductImportLogResource::canViewAny(), 'Staf biasa tidak boleh, walau menu_access kosong (semua menu).');
        $this->assertFalse(ProductImportLogResource::canView(new ProductImportLog()));

        $this->actingAs($this->user('kasir', ['ProductImportLogResource']), 'web');
        $this->assertFalse(ProductImportLogResource::canViewAny(), 'Kotak menu saja tidak cukup.');
    }

    public function test_the_history_is_read_only(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $record = new ProductImportLog();

        $this->assertFalse(ProductImportLogResource::canCreate());
        $this->assertFalse(ProductImportLogResource::canEdit($record));
        $this->assertFalse(ProductImportLogResource::canDelete($record));
        $this->assertFalse(ProductImportLogResource::canDeleteAny());
        $this->assertArrayNotHasKey('create', ProductImportLogResource::getPages());
        $this->assertArrayNotHasKey('edit', ProductImportLogResource::getPages());
    }

    public function test_menu_order_does_not_clash_with_master_resep(): void
    {
        $this->assertNotSame(\App\Filament\Resources\MasterResepResource::getNavigationSort(), ProductImportLogResource::getNavigationSort());
    }

    public function test_list_is_newest_first_with_columns_and_search(): void
    {
        $admin = $this->user('super_admin', null, ['name' => 'Admin Impor']);
        $this->actingAs($admin, 'web');
        $old = $this->log('harga-lama.xlsx', '2026-10-01 09:00:00', $admin, 20, 20, 0);
        $new = $this->log('harga-baru.xlsx', '2026-10-07 15:30:00', $admin, 10, 8, 2);
        $orphan = $this->log('tanpa-user.csv', '2026-10-05 10:00:00', null, 5, 0, 5);

        Livewire::test(ListProductImportLogs::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$new, $orphan, $old], inOrder: true)
            ->assertTableColumnFormattedStateSet('created_at', '07 Oct 2026, 15:30', $new)
            ->assertTableColumnStateSet('user.name', 'Admin Impor', $new)
            ->assertTableColumnStateSet('total_rows', 10, $new)
            ->assertTableColumnStateSet('updated_count', 8, $new)
            ->assertTableColumnStateSet('skipped_count', 2, $new)
            ->searchTable('tanpa-user')
            ->assertCanSeeTableRecords([$orphan])
            ->assertCanNotSeeTableRecords([$old, $new]);
    }

    public function test_a_log_without_a_user_still_shows_with_a_dash(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $orphan = $this->log('tanpa-user.csv', '2026-10-05 10:00:00');

        Livewire::test(ListProductImportLogs::class)
            ->assertCanSeeTableRecords([$orphan])
            ->assertSee('—');

        Livewire::test(ViewProductImportLog::class, ['record' => $orphan->getKey()])
            ->assertSuccessful()
            ->assertSee('tanpa-user.csv');
    }

    public function test_detail_shows_the_counts_and_one_line_per_error(): void
    {
        $admin = $this->user('super_admin', null, ['name' => 'Admin Impor']);
        $this->actingAs($admin, 'web');
        $log = $this->log('harga.xlsx', '2026-10-07 15:30:00', $admin, 10, 7, 3, [
            'Baris 3: SKU "XYZ-1" tidak ditemukan di katalog',
            'Baris 5: harga "abc" bukan angka',
            'Baris 9: harga negatif',
        ]);

        $page = Livewire::test(ViewProductImportLog::class, ['record' => $log->getKey()]);

        $page->assertSuccessful()
            ->assertSee('harga.xlsx')
            ->assertSee('Admin Impor')
            ->assertSee('07 Oct 2026, 15:30')
            ->assertSee('Detail Baris Dilewati/Error')
            ->assertSee('Baris 3: SKU "XYZ-1" tidak ditemukan di katalog')
            ->assertSee('Baris 5: harga "abc" bukan angka')
            ->assertSee('Baris 9: harga negatif');

        // Satu butir daftar per error, bukan satu paragraf panjang.
        $this->assertGreaterThanOrEqual(3, substr_count($page->html(), '<li'));
    }

    public function test_the_error_section_is_hidden_when_nothing_failed(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $clean = $this->log('bersih.xlsx', '2026-10-07 15:30:00', null, 4, 4, 0, null);
        $empty = $this->log('kosong.xlsx', '2026-10-07 16:30:00', null, 4, 4, 0, []);

        Livewire::test(ViewProductImportLog::class, ['record' => $clean->getKey()])->assertDontSee('Detail Baris Dilewati/Error');
        Livewire::test(ViewProductImportLog::class, ['record' => $empty->getKey()])->assertDontSee('Detail Baris Dilewati/Error');
    }

    public function test_non_full_access_accounts_are_refused_the_pages(): void
    {
        $log = $this->log('rahasia.xlsx', '2026-10-07 15:30:00');

        // Akun aktif, jadi 403 di bawah murni karena bukan full-access (bukan karena ditolak panel).
        $this->actingAs($this->user('super_admin'), 'web');
        $this->get(ProductImportLogResource::getUrl('index'))->assertOk();
        $this->get(ProductImportLogResource::getUrl('view', ['record' => $log]))->assertOk();

        $this->actingAs($this->user('kasir'), 'web');
        $this->get(ProductImportLogResource::getUrl('index'))->assertForbidden();
        $this->get(ProductImportLogResource::getUrl('view', ['record' => $log]))->assertForbidden();
    }
}

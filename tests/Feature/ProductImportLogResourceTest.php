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
        app()->setLocale('id'); // bulan tampil Indonesia seperti di production, apa pun APP_LOCALE di .env
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
        $this->assertFalse(ProductImportLogResource::canViewAny(), 'Staff biasa tidak boleh, walau menu_access kosong (semua menu).');
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
        $admin = $this->user('super_admin', null, ['name' => 'Admin Import']);
        $this->actingAs($admin, 'web');
        $old = $this->log('harga-lama.xlsx', '2026-10-01 09:00:00', $admin, 20, 20, 0);
        $new = $this->log('harga-baru.xlsx', '2026-10-07 15:30:00', $admin, 10, 8, 2);
        $orphan = $this->log('tanpa-user.csv', '2026-10-05 10:00:00', null, 5, 0, 5);

        Livewire::test(ListProductImportLogs::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$new, $orphan, $old], inOrder: true)
            ->assertTableColumnFormattedStateSet('created_at', '07 Okt 2026, 15:30', $new)
            ->assertTableColumnStateSet('user.name', 'Admin Import', $new)
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
        $admin = $this->user('super_admin', null, ['name' => 'Admin Import']);
        $this->actingAs($admin, 'web');
        $log = $this->log('harga.xlsx', '2026-10-07 15:30:00', $admin, 10, 7, 3, [
            'Baris 3: SKU "XYZ-1" tidak ditemukan di katalog',
            'Baris 5: harga "abc" bukan angka',
            'Baris 9: harga negatif',
        ]);

        $page = Livewire::test(ViewProductImportLog::class, ['record' => $log->getKey()]);

        $page->assertSuccessful()
            ->assertSee('harga.xlsx')
            ->assertSee('Admin Import')
            ->assertSee('07 Okt 2026, 15:30')
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

        // Staf biasa: tidak boleh mendapat halamannya (403 atau dialihkan Filament), dan isinya tidak boleh bocor.
        $this->actingAs($this->user('kasir'), 'web');
        foreach ([ProductImportLogResource::getUrl('index'), ProductImportLogResource::getUrl('view', ['record' => $log])] as $url) {
            $response = $this->get($url);
            $this->assertContains($response->getStatusCode(), [302, 403], $url);
            $response->assertDontSee('rahasia.xlsx');
        }
    }

    // ------------------------------------------------------------- perubahan, filter, gerbang

    public function test_the_detail_lists_the_changes_with_old_and_new_values_and_notes(): void
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');
        $log = ProductImportLog::create([
            'user_id' => $admin->id, 'filename' => 'harga.xlsx', 'total_rows' => 2, 'updated_count' => 2, 'skipped_count' => 0, 'errors' => null,
            'changes' => [
                ['sku' => 'PPF-1', 'product' => 'PPF Premium', 'field' => 'Harga Dasar', 'old' => 'Rp1.000.000', 'new' => 'Rp1.100.000', 'note' => null],
                ['sku' => 'PPF-2', 'product' => 'PPF Per Ukuran', 'field' => 'Harga Dasar', 'old' => 'Rp0', 'new' => 'Rp900.000', 'note' => 'Produk ini punya harga per ukuran yang menang atas harga dasar, jadi harga jual per ukuran tidak berubah.'],
            ],
        ]);

        $page = Livewire::test(ViewProductImportLog::class, ['record' => $log->getKey()]);

        $page->assertSee('Perubahan yang Dilakukan')
            ->assertSee('PPF-1 (PPF Premium) — Harga Dasar: Rp1.000.000 → Rp1.100.000', false)
            ->assertSee('harga jual per ukuran tidak berubah');

        $clean = $this->log('tanpa-perubahan.xlsx', '2026-10-07 15:30:00');
        Livewire::test(ViewProductImportLog::class, ['record' => $clean->getKey()])->assertDontSee('Perubahan yang Dilakukan');
    }

    public function test_the_skipped_filter_and_the_changes_column(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $clean = $this->log('bersih.xlsx', '2026-10-07 15:30:00', null, 4, 4, 0);
        $messy = $this->log('berantakan.xlsx', '2026-10-07 16:30:00', null, 4, 2, 2, ['Baris 3: SKU kosong, dilewati.']);
        $messy->update(['changes' => [['sku' => 'X', 'product' => 'P', 'field' => 'Harga Dasar', 'old' => 'Rp1', 'new' => 'Rp2', 'note' => null]]]);

        Livewire::test(ListProductImportLogs::class)
            ->assertCanSeeTableRecords([$clean, $messy])
            ->filterTable('has_skipped', true)
            ->assertCanSeeTableRecords([$messy])
            ->assertCanNotSeeTableRecords([$clean]);

        Livewire::test(ListProductImportLogs::class)->assertTableColumnStateSet('changes_count', 1, $messy->fresh())->assertTableColumnStateSet('changes_count', 0, $clean);
    }

    public function test_the_gate_is_full_access_only_and_the_menu_box_is_no_longer_offered(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(ProductImportLogResource::canViewAny());

        $options = collect((fn () => static::menuAccessOptions())->bindTo(null, \App\Filament\Resources\UserResource::class)())->flatMap(fn ($g) => array_keys($g))->all();
        $this->assertNotContains('ProductImportLogResource', $options, 'Kotak yang tidak membuka apa pun bagi staf biasa dihapus dari Akses Menu.');
    }
}

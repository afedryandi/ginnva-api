<?php

namespace Tests\Feature;

use App\Exports\FilmProductExport;
use App\Filament\Resources\FilmProductResource;
use App\Filament\Resources\FilmProductResource\Pages\CreateFilmProduct;
use App\Filament\Resources\FilmProductResource\Pages\EditFilmProduct;
use App\Filament\Resources\FilmProductResource\Pages\ListFilmProducts;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\FilmProduct;
use App\Models\FilmProductPrice;
use App\Models\ScrollCode;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Daftar Produk (katalog film/jasa): hak akses lewat kotak menu, daftar + pencarian + filter, status harga, tambah / ubah
 * dengan validasi (SKU unik termasuk yang sudah dihapus), hapus lembut (soft delete) + pulihkan + hapus massal, jejak audit,
 * produk terhapus tetap terbaca oleh booking / roll, ekspor Excel/PDF, impor hanya untuk full-access.
 * Migrasi sudah menanam produk awal, jadi tes memakai SKU sendiri dan tidak menghitung seluruh tabel.
 */
class FilmProductResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $menuAccess = null): User
    {
        $store = Store::firstOrCreate(['name' => 'Toko Test'], ['city' => 'Jakarta', 'address' => 'Jl. A', 'is_active' => true]);

        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'menu_access' => $menuAccess]), fn (User $u) => $u->assignRole($role));
    }

    private function admin(): User
    {
        return tap($this->user('super_admin'), fn ($u) => $this->actingAs($u, 'web'));
    }

    private function product(string $sku, string $type = 'window_film', float $price = 100000, bool $active = true, string $position = 'front'): FilmProduct
    {
        return FilmProduct::create(['sku' => $sku, 'name' => "Produk {$sku}", 'product_type' => $type, 'position' => $position, 'base_price' => $price, 'is_active' => $active]);
    }

    // ------------------------------------------------------------- akses

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(FilmProductResource::canViewAny());
        $this->assertTrue(FilmProductResource::canCreate());

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertTrue(FilmProductResource::canViewAny(), 'menu_access kosong = semua menu.');
        $this->assertTrue(FilmProductResource::canCreate());
        $this->assertTrue(FilmProductResource::canEdit(new FilmProduct()));
        $this->assertTrue(FilmProductResource::canDelete(new FilmProduct()));
        $this->assertTrue(FilmProductResource::canDeleteAny());

        $this->actingAs($this->user('kasir', ['FilmProductResource']), 'web');
        $this->assertTrue(FilmProductResource::canViewAny());

        $this->actingAs($this->user('kasir', ['BookingResource']), 'web');
        $this->assertFalse(FilmProductResource::canViewAny());
        $this->assertFalse(FilmProductResource::canCreate());
        $this->assertFalse(FilmProductResource::canDeleteAny());
    }

    // ------------------------------------------------------------- daftar

    public function test_list_shows_products_with_search_and_filters(): void
    {
        $this->admin();
        $film = $this->product('TST-FILM', 'window_film', 150000);
        $ppf = $this->product('TST-PPF', 'ppf', 900000);
        $off = $this->product('TST-OFF', 'window_film', 50000, false);

        Livewire::test(ListFilmProducts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$film, $ppf, $off])
            ->searchTable('TST-PPF')
            ->assertCanSeeTableRecords([$ppf])
            ->assertCanNotSeeTableRecords([$film, $off])
            ->searchTable('Produk TST-FILM')
            ->assertCanSeeTableRecords([$film])
            ->assertCanNotSeeTableRecords([$ppf]);

        Livewire::test(ListFilmProducts::class)
            ->searchTable('TST-')
            ->filterTable('product_type', 'ppf')
            ->assertCanSeeTableRecords([$ppf])
            ->assertCanNotSeeTableRecords([$film, $off]);

        Livewire::test(ListFilmProducts::class)
            ->searchTable('TST-')
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$off])
            ->assertCanNotSeeTableRecords([$film, $ppf]);
    }

    public function test_columns_show_type_position_and_price_status_labels(): void
    {
        $this->admin();
        $film = $this->product('TST-FILM', 'window_film', 150000, true, 'side_rear');
        $ppf = $this->product('TST-PPF', 'ppf', 0);
        $sized = $this->product('TST-SIZED', 'ppf', 0);
        FilmProductPrice::create(['film_product_id' => $sized->id, 'vehicle_size' => 'M', 'price' => 800000]);
        $wash = $this->product('TST-WASH', 'premium_wash', 75000);

        Livewire::test(ListFilmProducts::class)
            ->assertTableColumnFormattedStateSet('product_type', 'Kaca Film', $film)
            ->assertTableColumnFormattedStateSet('product_type', 'PPF', $ppf)
            ->assertTableColumnFormattedStateSet('product_type', 'Premium Wash', $wash)
            ->assertTableColumnFormattedStateSet('position', 'Samping & Belakang', $film)
            ->assertTableColumnFormattedStateSet('position', '—', $ppf)
            ->assertTableColumnStateSet('price_status', 'Terisi', $film)
            ->assertTableColumnStateSet('price_status', 'Belum diisi', $ppf)
            ->assertTableColumnStateSet('price_status', 'Terisi', $sized);
    }

    // ------------------------------------------------------------- tambah / ubah

    public function test_a_product_can_be_created_and_is_audited(): void
    {
        $admin = $this->admin();

        Livewire::test(CreateFilmProduct::class)
            ->fillForm(['sku' => 'NEW-1', 'name' => 'Ginnva Baru', 'product_type' => 'window_film', 'position' => 'side_rear', 'base_price' => 250000, 'is_active' => true, 'tracks_batch' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = FilmProduct::where('sku', 'NEW-1')->firstOrFail();
        $this->assertSame(['Ginnva Baru', 'window_film', 'side_rear', true], [$product->name, $product->product_type, $product->position, $product->tracks_batch]);
        $this->assertEqualsWithDelta(250000.0, (float) $product->base_price, 0.001);

        $log = Activity::where('log_name', 'film_product')->where('subject_id', $product->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertStringContainsString('ditambahkan', $log->description);
    }

    public function test_a_ppf_product_needs_no_glass_position(): void
    {
        $this->admin();

        Livewire::test(CreateFilmProduct::class)
            ->fillForm(['sku' => 'NEW-PPF', 'name' => 'PPF Baru', 'product_type' => 'ppf', 'base_price' => 0])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('film_products', ['sku' => 'NEW-PPF', 'product_type' => 'ppf']);
    }

    public function test_required_fields_and_price_rules_are_enforced(): void
    {
        $this->admin();

        Livewire::test(CreateFilmProduct::class)
            ->fillForm(['sku' => '', 'name' => '', 'product_type' => null, 'base_price' => null])
            ->call('create')
            ->assertHasFormErrors(['sku' => 'required', 'name' => 'required', 'product_type' => 'required', 'base_price' => 'required']);

        Livewire::test(CreateFilmProduct::class)
            ->fillForm(['sku' => 'NEG-1', 'name' => 'Negatif', 'product_type' => 'ppf', 'base_price' => -1])
            ->call('create')
            ->assertHasFormErrors(['base_price']);

        Livewire::test(CreateFilmProduct::class)
            ->fillForm(['sku' => 'WF-NOPOS', 'name' => 'Tanpa posisi', 'product_type' => 'window_film', 'position' => null, 'base_price' => 1000])
            ->call('create')
            ->assertHasFormErrors(['position' => 'required']);

        $this->assertDatabaseMissing('film_products', ['sku' => 'NEG-1']);
    }

    public function test_the_sku_must_be_unique_even_against_deleted_products(): void
    {
        $this->admin();
        $this->product('DUP-1');
        $gone = $this->product('GONE-1');
        $gone->delete();

        Livewire::test(CreateFilmProduct::class)
            ->fillForm(['sku' => 'DUP-1', 'name' => 'Kembar', 'product_type' => 'ppf', 'base_price' => 1])
            ->call('create')
            ->assertHasFormErrors(['sku' => 'unique']);

        Livewire::test(CreateFilmProduct::class)
            ->fillForm(['sku' => 'GONE-1', 'name' => 'Kembar hapus', 'product_type' => 'ppf', 'base_price' => 1])
            ->call('create')
            ->assertHasFormErrors(['sku' => 'unique']);
    }

    public function test_a_product_can_be_edited_keeping_its_own_sku_and_changes_are_audited(): void
    {
        $this->admin();
        $product = $this->product('EDIT-1', 'window_film', 100000);

        Livewire::test(EditFilmProduct::class, ['record' => $product->getKey()])
            ->assertFormSet(['sku' => 'EDIT-1', 'base_price' => '100000.00'])
            ->fillForm(['name' => 'Nama Baru', 'base_price' => 175000, 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame(['EDIT-1', 'Nama Baru', false], [$product->sku, $product->name, $product->is_active]);
        $this->assertEqualsWithDelta(175000.0, (float) $product->base_price, 0.001);

        $log = Activity::where('log_name', 'film_product')->where('subject_id', $product->id)->where('description', 'like', '%diubah%')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEqualsWithDelta(175000.0, (float) $log->properties['attributes']['base_price'], 0.001);
        $this->assertEqualsWithDelta(100000.0, (float) $log->properties['old']['base_price'], 0.001);
    }

    // ------------------------------------------------------------- hapus / pulihkan

    public function test_row_delete_is_a_soft_delete_and_restore_brings_it_back(): void
    {
        $this->admin();
        $product = $this->product('DEL-1');

        Livewire::test(ListFilmProducts::class)
            ->callTableAction('delete', $product)
            ->assertHasNoTableActionErrors();

        $this->assertSoftDeleted('film_products', ['id' => $product->id]);
        $this->assertNotNull(Activity::where('log_name', 'film_product')->where('subject_id', $product->id)->where('description', 'like', '%dihapus%')->first());

        // Model $product di memori belum tahu sudah terhapus (dihapus lewat instance lain) -- ambil ulang termasuk yang terhapus.
        $trashed = FilmProduct::withTrashed()->findOrFail($product->id);

        Livewire::test(ListFilmProducts::class)
            ->assertCanNotSeeTableRecords([$product])
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$trashed])
            ->assertTableActionVisible('restore', $trashed)
            ->callTableAction('restore', $trashed)
            ->assertHasNoTableActionErrors();

        $this->assertNull($product->fresh()->deleted_at);
    }

    public function test_the_restore_action_is_not_offered_for_active_products(): void
    {
        $this->admin();
        $product = $this->product('ACT-1');

        Livewire::test(ListFilmProducts::class)->assertTableActionHidden('restore', $product);
    }

    public function test_bulk_delete_soft_deletes_the_selected_products(): void
    {
        $this->admin();
        $a = $this->product('BULK-A');
        $b = $this->product('BULK-B');
        $keep = $this->product('BULK-KEEP');

        Livewire::test(ListFilmProducts::class)
            ->callTableBulkAction('delete', [$a, $b])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSoftDeleted('film_products', ['id' => $a->id]);
        $this->assertSoftDeleted('film_products', ['id' => $b->id]);
        $this->assertNull($keep->fresh()->deleted_at);
    }

    public function test_a_deleted_product_is_still_readable_by_old_bookings_and_rolls(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000009']);
        $product = $this->product('HIST-1');
        $booking = Booking::create([
            'booking_number' => 'BKG-HIST', 'customer_id' => $customer->id, 'store_id' => $store->id, 'film_product_id' => $product->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-05', 'status' => 'completed',
        ]);
        $roll = ScrollCode::create(['code' => 'ROLL-HIST', 'film_product_id' => $product->id, 'store_id' => $store->id, 'status' => 'allocated', 'usage_count' => 0, 'allocated_at' => now()]);

        $product->delete();

        $this->assertSame('HIST-1', $booking->fresh()->filmProduct->sku);
        $this->assertSame('HIST-1', $roll->fresh()->filmProduct->sku, 'Roll yang masih memakai produk ini tetap menampilkan produknya.');
    }

    public function test_the_edit_page_header_can_delete_the_product(): void
    {
        $this->admin();
        $product = $this->product('HDR-1');

        Livewire::test(EditFilmProduct::class, ['record' => $product->getKey()])
            ->callAction('delete')
            ->assertHasNoActionErrors();

        $this->assertSoftDeleted('film_products', ['id' => $product->id]);
    }

    // ------------------------------------------------------------- ekspor & impor

    public function test_export_contains_every_active_product_with_labels_and_numeric_prices(): void
    {
        $film = $this->product('EXP-FILM', 'window_film', 150000, true, 'side_rear');
        $sized = $this->product('EXP-PPF', 'ppf', 0, false);
        FilmProductPrice::create(['film_product_id' => $sized->id, 'vehicle_size' => 'S', 'price' => 700000]);
        FilmProductPrice::create(['film_product_id' => $sized->id, 'vehicle_size' => 'L', 'price' => 900000]);
        $this->product('EXP-GONE')->delete();

        $export = new FilmProductExport();
        $rows = $export->collection()->keyBy(0);

        $this->assertSame(['SKU', 'Nama Produk', 'Tipe', 'Posisi Kaca', 'Harga Dasar (Flat)', 'Harga per Ukuran', 'Aktif'], $export->headings());
        $this->assertEquals(['EXP-FILM', 'Produk EXP-FILM', 'Kaca Film', 'Samping & Belakang', 150000.0, '-', 'Ya'], $rows['EXP-FILM']);
        $this->assertSame('PPF', $rows['EXP-PPF'][2]);
        $this->assertSame('-', $rows['EXP-PPF'][3], 'Posisi kaca hanya untuk Kaca Film.');
        $this->assertSame('Tidak', $rows['EXP-PPF'][6]);
        $this->assertEqualsCanonicalizing(['S: Rp700.000', 'L: Rp900.000'], explode('; ', $rows['EXP-PPF'][5]));
        $this->assertFalse($rows->has('EXP-GONE'), 'Produk terhapus tidak ikut.');
    }

    public function test_export_actions_download(): void
    {
        $this->admin();
        $this->product('EXP-1');
        Excel::fake();

        $page = Livewire::test(ListFilmProducts::class);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('daftar-produk-20261008-100000.xlsx');
    }

    public function test_import_and_template_actions_are_for_full_access_only(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(ListFilmProducts::class)
            ->assertActionVisible('importPrices')
            ->assertActionVisible('downloadImportTemplate');

        $this->actingAs($this->user('kasir'), 'web');
        Livewire::test(ListFilmProducts::class)
            ->assertActionHidden('importPrices')
            ->assertActionHidden('downloadImportTemplate');
    }
}

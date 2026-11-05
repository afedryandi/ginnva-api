<?php

namespace Tests\Feature;

use App\Exports\VehicleExport;
use App\Filament\Pages\MasterDataCenter;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Filament\Resources\StoreResource\Pages\ListStores;
use App\Filament\Resources\VehicleGlassAreaResource\Pages\ListVehicleGlassAreas;
use App\Filament\Resources\VehicleResource\Pages\ListVehicles;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGlassArea;
use App\Services\MasterDataQualityService;
use App\Services\VehicleImportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Audit Master Data (Pusat Master Data, Kendaraan, Luas Kaca, Toko/Dealer): tautan ke baris bermasalah, filter pembersihan,
 * export/import kendaraan, aksi lintas menu, status buka toko, kelengkapan toko, dan pembatasan form Toko untuk non-akses penuh.
 */
class MasterDataAuditBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Vehicle::query()->delete();
        Store::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'menu_access' => null, 'is_active' => true]);
        $user->assignRole('super_admin');
        $this->actingAs($user, 'web');

        return $user;
    }

    private function vehicle(string $brand, ?string $model, string $size = 'M', array $extra = []): Vehicle
    {
        return Vehicle::create(array_merge(['brand' => $brand, 'model' => $model, 'size_category' => $size], $extra));
    }

    public function test_every_check_with_a_filter_links_to_a_filtered_list(): void
    {
        $this->admin();
        $page = new MasterDataCenter();

        foreach (['vehicle_no_model', 'vehicle_no_body', 'vehicle_no_area', 'vehicle_size_conflict', 'area_no_vehicle', 'store_no_code', 'store_no_coordinates', 'store_no_hours', 'store_no_manager', 'store_no_capacity', 'store_no_phone', 'product_unpriced'] as $key) {
            $link = $page->linkForCheck(['key' => $key]);
            $this->assertNotNull($link, $key);
            $this->assertStringContainsString('tableFilters', $link, $key);
        }
    }

    public function test_vehicle_cleanup_filters_match_the_data_center_counts(): void
    {
        $this->admin();
        $noModel = $this->vehicle('Honda', null);
        $noBody = $this->vehicle('Toyota', 'Avanza');
        $withArea = $this->vehicle('Honda', 'Civic', 'M', ['body_type' => 'sedan']);
        VehicleGlassArea::create(['brand' => 'honda', 'model' => 'CIVIC', 'front_sqm' => 1, 'side_sqm' => 1, 'rear_sqm' => 1, 'sunroof_sqm' => 0]);

        Livewire::test(ListVehicles::class)
            ->filterTable('no_model')
            ->assertCanSeeTableRecords([$noModel])
            ->assertCanNotSeeTableRecords([$noBody, $withArea]);

        Livewire::test(ListVehicles::class)
            ->filterTable('no_area')
            ->assertCanSeeTableRecords([$noBody])
            ->assertCanNotSeeTableRecords([$withArea]);

        $conflictA = $this->vehicle('Suzuki', 'Ertiga', 'M');
        $conflictB = $this->vehicle('Suzuki', 'Ertiga', 'L');

        Livewire::test(ListVehicles::class)
            ->filterTable('size_conflict')
            ->assertCanSeeTableRecords([$conflictA, $conflictB])
            ->assertCanNotSeeTableRecords([$withArea]);
    }

    public function test_vehicle_duplicate_as_variant_copies_the_rest_and_refuses_an_existing_variant(): void
    {
        $this->admin();
        $base = $this->vehicle('Honda', 'Civic', 'M', ['variant' => '1.5 RS', 'body_type' => 'sedan']);

        Livewire::test(ListVehicles::class)
            ->callTableAction('duplicate', $base, data: ['variant' => '1.5 RS'])
            ->assertHasTableActionErrors(['variant']);

        Livewire::test(ListVehicles::class)
            ->callTableAction('duplicate', $base, data: ['variant' => '2.0 Turbo'])
            ->assertHasNoTableActionErrors();

        $copy = Vehicle::where('variant', '2.0 Turbo')->firstOrFail();
        $this->assertSame('Civic', $copy->model);
        $this->assertSame('M', $copy->size_category);
        $this->assertSame('sedan', $copy->body_type);
    }

    public function test_export_headings_match_what_the_import_reads(): void
    {
        $vehicle = $this->vehicle('Honda', 'Civic', 'L', ['variant' => 'RS', 'body_type' => 'sedan', 'year_from' => 2019, 'notes' => 'Gen 11', 'is_active' => true]);
        $export = new VehicleExport();

        $this->assertSame(['Merek', 'Tipe/Model', 'Varian', 'Ukuran', 'Tipe Bodi', 'Tahun Dari', 'Tahun Sampai', 'Catatan', 'Aktif'], $export->headings());
        $this->assertSame(['Honda', 'Civic', 'RS', 'L', 'Sedan', 2019, null, 'Gen 11', 'Ya'], $export->map($vehicle));

        $result = app(VehicleImportService::class)->analyze([$export->map($vehicle)]);
        $this->assertSame(1, $result['summary']['unchanged'], 'Baris hasil export yang diimpor kembali tidak boleh dianggap berbeda.');
    }

    public function test_import_adds_new_vehicles_and_only_updates_existing_when_asked(): void
    {
        $existing = $this->vehicle('Honda', 'Civic', 'M');
        $service = app(VehicleImportService::class);

        $rows = [
            ['Honda', 'Civic', '', 'L', 'Sedan', '', '', '', 'Ya'],        // ada, ukuran berbeda
            ['Toyota', 'Raize', '', 'S', 'SUV', 2021, '', '', 'Ya'],        // baru
            ['Toyota', 'Raize', '', 'S', 'SUV', 2021, '', '', 'Ya'],        // ganda
            ['', 'Tanpa Merek', '', 'M', '', '', '', '', 'Ya'],             // bermasalah
            ['Daihatsu', 'Sigra', '', 'XS', '', '', '', '', 'Ya'],          // ukuran salah
        ];
        $analysis = $service->analyze($rows);

        $this->assertSame(1, $analysis['summary']['new']);
        $this->assertSame(1, $analysis['summary']['changed']);
        $this->assertSame(1, $analysis['summary']['duplicates']);
        $this->assertSame(2, $analysis['summary']['invalid']);

        $service->import($analysis['entries'], false);
        $this->assertSame('M', $existing->fresh()->size_category, 'Tanpa opsi perbarui, kendaraan yang ada tidak diubah.');
        $this->assertTrue(Vehicle::where('brand', 'Toyota')->where('model', 'Raize')->exists());

        $service->import($analysis['entries'], true);
        $this->assertSame('L', $existing->fresh()->size_category);
    }

    public function test_glass_area_without_catalog_vehicle_can_create_it_and_vehicle_can_fill_its_area(): void
    {
        $this->admin();
        $orphan = VehicleGlassArea::create(['brand' => 'Wuling', 'model' => 'Cloud', 'front_sqm' => 1, 'side_sqm' => 2, 'rear_sqm' => 1, 'sunroof_sqm' => 0]);

        Livewire::test(ListVehicleGlassAreas::class)
            ->filterTable('no_vehicle')
            ->assertCanSeeTableRecords([$orphan])
            ->callTableAction('createVehicle', $orphan, data: ['size_category' => 'M'])
            ->assertHasNoTableActionErrors();

        $this->assertTrue(Vehicle::where('brand', 'Wuling')->where('model', 'Cloud')->where('size_category', 'M')->exists());

        $vehicle = $this->vehicle('Hyundai', 'Creta', 'L');
        Livewire::test(ListVehicles::class)
            ->callTableAction('addGlassArea', $vehicle, data: ['front_sqm' => 0.9, 'side_sqm' => 2.1, 'rear_sqm' => 0.8, 'sunroof_sqm' => 0])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, VehicleGlassArea::where('brand_key', 'hyundai')->where('model_key', 'creta')->count());
    }

    public function test_store_open_status_follows_todays_hours_in_wib(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true,
            'opening_hours' => [
                ['days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'closed' => false, 'open' => '09:00', 'close' => '17:00'],
                ['days' => ['sun'], 'closed' => true],
            ]]);

        // Senin 2026-11-02, 10:00 WIB = 03:00 UTC.
        $this->assertSame(['open_now' => true, 'today_hours' => '09:00–17:00'], $store->openStatus(Carbon::parse('2026-11-02 03:00:00', 'UTC')));
        // Senin 18:00 WIB = 11:00 UTC: sudah tutup.
        $this->assertSame(['open_now' => false, 'today_hours' => '09:00–17:00'], $store->openStatus(Carbon::parse('2026-11-02 11:00:00', 'UTC')));
        // Minggu libur.
        $this->assertSame(['open_now' => false, 'today_hours' => 'Libur'], $store->openStatus(Carbon::parse('2026-11-01 03:00:00', 'UTC')));
        // Sabtu tidak ada barisnya: tidak diketahui.
        $this->assertSame(['open_now' => null, 'today_hours' => null], $store->openStatus(Carbon::parse('2026-11-07 03:00:00', 'UTC')));
    }

    public function test_public_store_api_exposes_open_status(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);

        $this->getJson('/api/stores/' . $store->id)
            ->assertOk()
            ->assertJsonPath('data.open_now', null)
            ->assertJsonPath('data.today_hours', null);
    }

    public function test_store_completeness_column_and_filters(): void
    {
        $this->admin();
        $complete = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Lengkap', 'is_active' => true, 'code' => 'BDG-01', 'phone' => '022-1', 'latitude' => -6.9, 'longitude' => 107.6,
            'detailing_slot_count' => 2, 'instalasi_qc_slot_count' => 2,
            'opening_hours' => [['days' => ['mon'], 'closed' => false, 'open' => '08:00', 'close' => '17:00']]]);
        $partial = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Kurang', 'is_active' => true]);

        $this->assertSame([], $complete->missingFields());
        $this->assertSame(['Kode', 'Telepon', 'Koordinat', 'Jam buka', 'Slot kapasitas'], $partial->missingFields());

        Livewire::test(ListStores::class)
            ->filterTable('incomplete')
            ->assertCanSeeTableRecords([$partial])
            ->assertCanNotSeeTableRecords([$complete]);

        Livewire::test(ListStores::class)
            ->filterTable('no_phone')
            ->assertCanSeeTableRecords([$partial])
            ->assertCanNotSeeTableRecords([$complete]);
    }

    public function test_staff_with_edit_permission_cannot_change_capacity_or_code_but_can_change_phone(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true, 'code' => 'JKT-01', 'install_capacity_per_day' => 3]);
        $staff = User::create(['name' => 'Staf', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id,
            'menu_access' => null, 'menu_permissions' => ['StoreResource' => ['update']], 'is_active' => true]);
        $staff->assignRole('kasir');
        $this->actingAs($staff, 'web');

        Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->fillForm(['phone' => '021-999', 'install_capacity_per_day' => 50, 'code' => 'HACK'])
            ->call('save');

        $fresh = $store->fresh();
        $this->assertSame('021-999', $fresh->phone);
        $this->assertSame(3, $fresh->install_capacity_per_day);
        $this->assertSame('JKT-01', $fresh->code);
    }

    public function test_new_store_checks_count_missing_phone_and_slots(): void
    {
        Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $checks = collect(app(MasterDataQualityService::class)->checks());

        $this->assertSame(1, $checks->firstWhere('key', 'store_no_phone')['count']);
        $this->assertSame(1, $checks->firstWhere('key', 'store_no_capacity')['count']);
        $this->assertSame('warn', $checks->firstWhere('key', 'store_no_phone')['severity']);
    }
}

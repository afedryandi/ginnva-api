<?php

namespace Tests\Feature;

use App\Filament\Pages\MasterDataCenter;
use App\Models\FilmProduct;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGlassArea;
use App\Services\MasterDataQualityService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Master Data Fase 5 (2026-10-21): Pusat Master Data, ringkasan kelengkapan baca-saja.
 */
class MasterDataCenterTest extends TestCase
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
        FilmProduct::query()->delete();
    }

    private function check(string $key): array
    {
        return collect(app(MasterDataQualityService::class)->checks())->firstWhere('key', $key);
    }

    private function vehicle(string $brand, ?string $model, string $size = 'M', array $extra = []): Vehicle
    {
        return Vehicle::create(array_merge(['brand' => $brand, 'model' => $model, 'size_category' => $size], $extra));
    }

    public function test_everything_is_clean_when_there_is_no_data(): void
    {
        foreach (app(MasterDataQualityService::class)->checks() as $check) {
            $this->assertSame(0, $check['count'], $check['key']);
        }
    }

    public function test_vehicle_checks(): void
    {
        $this->vehicle('Honda', null);                                    // merek saja
        $this->vehicle('Honda', 'Civic', 'M', ['body_type' => 'sedan']);
        $this->vehicle('Toyota', 'Avanza');                               // tanpa tipe bodi
        $this->vehicle('Toyota', 'Raize', 'S', ['is_active' => false]);   // nonaktif: tidak diperiksa

        $this->assertSame(1, $this->check('vehicle_no_model')['count']);
        $this->assertSame(1, $this->check('vehicle_no_body')['count']);
        $this->assertSame(2, $this->check('vehicle_no_body')['total']);

        VehicleGlassArea::create(['brand' => 'HONDA', 'model' => 'civic', 'front_sqm' => 1]);
        $this->assertSame(1, $this->check('vehicle_no_area')['count'], 'Civic punya luas kaca (huruf tidak membedakan), Avanza belum.');
        $this->assertSame(2, $this->check('vehicle_no_area')['total']);
    }

    public function test_size_conflicts_are_flagged_as_important(): void
    {
        $this->vehicle('Hyundai', 'IONIQ 5', 'S');
        $this->vehicle('Hyundai', 'IONIQ 5', 'M');
        $this->vehicle('Honda', 'Civic', 'M');

        $conflict = $this->check('vehicle_size_conflict');

        $this->assertSame(1, $conflict['count']);
        $this->assertSame('warn', $conflict['severity']);
        $this->assertSame(1, app(MasterDataQualityService::class)->attentionCount());
    }

    public function test_glass_areas_without_a_catalog_vehicle(): void
    {
        $this->vehicle('Honda', 'Civic');
        VehicleGlassArea::create(['brand' => 'Honda', 'model' => 'Civic', 'front_sqm' => 1]);
        VehicleGlassArea::create(['brand' => 'Mazda', 'model' => 'CX-5', 'front_sqm' => 1]);

        $check = $this->check('area_no_vehicle');

        $this->assertSame(1, $check['count']);
        $this->assertSame(2, $check['total']);
    }

    public function test_store_checks_only_look_at_active_stores(): void
    {
        Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true, 'code' => 'BDG-01', 'latitude' => -6.9, 'longitude' => 107.6,
            'opening_hours' => [['days' => ['mon'], 'closed' => false, 'open' => '08:00', 'close' => '17:00']]]);
        Store::create(['city' => 'Tutup', 'address' => 'Jl. C', 'name' => 'Toko C', 'is_active' => false]);

        $this->assertSame(1, $this->check('store_no_code')['count']);
        $this->assertSame(2, $this->check('store_no_code')['total']);
        $this->assertSame(1, $this->check('store_no_coordinates')['count']);
        $this->assertSame(1, $this->check('store_no_hours')['count']);
        $this->assertSame(2, $this->check('store_no_manager')['count']);
    }

    public function test_unpriced_active_products_are_flagged(): void
    {
        FilmProduct::create(['sku' => 'WF-1', 'name' => 'Tint A', 'product_type' => 'window_film', 'position' => 'front', 'base_price' => 0, 'is_active' => true]);
        FilmProduct::create(['sku' => 'WF-2', 'name' => 'Tint B', 'product_type' => 'window_film', 'position' => 'front', 'base_price' => 150000, 'is_active' => true]);
        FilmProduct::create(['sku' => 'WF-3', 'name' => 'Tint C', 'product_type' => 'window_film', 'position' => 'front', 'base_price' => 0, 'is_active' => false]);

        $check = $this->check('product_unpriced');

        $this->assertSame(1, $check['count']);
        $this->assertSame(2, $check['total']);
    }

    public function test_the_page_renders_for_full_access_and_staff_but_it_is_read_only(): void
    {
        $this->vehicle('Hyundai', 'IONIQ 5', 'S');
        $this->vehicle('Hyundai', 'IONIQ 5', 'M');

        $admin = tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
        $this->actingAs($admin, 'web');
        Livewire::test(MasterDataCenter::class)
            ->assertSuccessful()
            ->assertSee('Merek+model dengan lebih dari satu ukuran')
            ->assertSee('Perlu ditindak')
            ->assertSee('Kendaraan');

        $staff = tap(User::create(['name' => 'Kasir', 'email' => uniqid() . '@test.local', 'password' => 'x', 'menu_access' => null]), fn (User $u) => $u->assignRole('kasir'));
        $this->actingAs($staff, 'web');
        Livewire::test(MasterDataCenter::class)->assertSuccessful();
    }

    public function test_the_page_says_all_clear_when_nothing_needs_action(): void
    {
        $admin = tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
        $this->actingAs($admin, 'web');

        Livewire::test(MasterDataCenter::class)->assertSee('Tidak ada pemeriksaan penting yang bermasalah');
    }
}

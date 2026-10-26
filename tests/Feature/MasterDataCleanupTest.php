<?php

namespace Tests\Feature;

use App\Filament\Resources\VehicleResource\Pages\CreateVehicle;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\VehicleModelSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Master Data Fase 0 (2026-10-21): kebersihan. Katalog kendaraan dibangun di satu tempat (dua endpoint publik konsisten), teks bantuan
 * ukuran tidak lagi menyebut model harga lama, dan seeder model kendaraan tidak membuat baris kedua untuk merek+model yang sama.
 */
class MasterDataCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // Migrasi sudah menanam data kendaraan; hapus supaya tes menghitung hanya data miliknya.
        Vehicle::query()->delete();
    }

    private function vehicle(string $brand, ?string $model, ?string $variant = null, string $size = 'M'): Vehicle
    {
        return Vehicle::create(['brand' => $brand, 'model' => $model, 'variant' => $variant, 'size_category' => $size]);
    }

    public function test_the_two_public_catalog_endpoints_return_the_same_vehicles_and_brands(): void
    {
        $avanza = $this->vehicle('Toyota', 'Avanza', '1.5 G', 'M');
        $raize = $this->vehicle('TOYOTA', 'Raize', null, 'S');
        $civic = $this->vehicle('Honda', 'Civic', null, 'M');
        $this->vehicle('Suzuki', null);   // merek saja: ada di daftar merek, bukan di daftar kendaraan

        $options = $this->getJson('/api/quotation/options')->assertSuccessful()->json('data');
        $catalog = $this->getJson('/api/vehicles/catalog')->assertSuccessful()->json('data');

        $this->assertSame($catalog['brands'], $options['brands']);
        $this->assertContains('Suzuki', $options['brands']);
        $this->assertSame(collect($catalog['vehicles'])->pluck('id')->all(), collect($options['vehicles'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$avanza->id, $raize->id, $civic->id], collect($options['vehicles'])->pluck('id')->all(), 'Entri tanpa model tidak ikut.');
    }

    public function test_the_quotation_options_shape_is_unchanged_and_the_catalog_adds_the_size(): void
    {
        $this->vehicle('Honda', 'Civic', 'RS', 'M');

        $options = $this->getJson('/api/quotation/options')->json('data');
        $catalog = $this->getJson('/api/vehicles/catalog')->json('data');

        $this->assertSame(['id', 'brand', 'model', 'variant'], array_keys($options['vehicles'][0]));
        $this->assertSame('M', $catalog['vehicles'][0]['size_category']);
        $this->assertArrayHasKey('products', $options);
    }

    public function test_every_vehicle_uses_the_exact_brand_casing_listed_in_the_brands(): void
    {
        $this->vehicle('Toyota', 'Avanza', null, 'M');
        $this->vehicle('TOYOTA', 'Raize', null, 'S');

        foreach (['/api/quotation/options', '/api/vehicles/catalog'] as $url) {
            $data = $this->getJson($url)->json('data');

            $this->assertCount(1, $data['brands'], 'Casing berbeda digabung jadi satu merek.');
            foreach ($data['vehicles'] as $v) {
                $this->assertContains($v['brand'], $data['brands'], 'Dropdown web/mobile membandingkan huruf besar kecil secara persis.');
            }
        }
    }

    public function test_the_size_helper_text_no_longer_mentions_the_old_price_model(): void
    {
        $admin = tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
        $this->actingAs($admin, 'web');

        Livewire::test(CreateVehicle::class)
            ->assertSuccessful()
            ->assertSee('harga dibaca dari matriks per ukuran')
            ->assertDontSee('base_price × coefficient');
    }

    public function test_the_model_seeder_is_idempotent_and_never_creates_a_second_row_for_the_same_brand_and_model(): void
    {
        $this->seed(VehicleModelSeeder::class);
        $first = Vehicle::count();
        $this->seed(VehicleModelSeeder::class);

        $this->assertSame($first, Vehicle::count(), 'Dijalankan ulang tidak menambah baris.');
        $this->assertGreaterThan(200, $first);

        $duplicates = DB::table('vehicles')->whereNotNull('model')->whereNull('variant')
            ->select('brand', 'model')->groupBy('brand', 'model')->havingRaw('COUNT(*) > 1')->get();
        $this->assertCount(0, $duplicates, 'Tidak ada merek+model ganda dengan ukuran berbeda.');
        $this->assertSame(1, Vehicle::where('brand', 'HYUNDAI')->where('model', 'IONIQ 5')->count());
        $this->assertSame('M', Vehicle::where('brand', 'HYUNDAI')->where('model', 'IONIQ 5')->value('size_category'), 'Keputusan pemilik: IONIQ 5 ukuran M.');
    }
}

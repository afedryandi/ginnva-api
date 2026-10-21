<?php

namespace Tests\Feature;

use App\Filament\Resources\VehicleResource\Pages\CreateVehicle;
use App\Filament\Resources\VehicleResource\Pages\EditVehicle;
use App\Filament\Resources\VehicleResource\Pages\ListVehicles;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Master Data Fase 1 (2026-10-21): siklus hidup kendaraan. Aktif/nonaktif menggantikan hapus untuk kendaraan yang sudah dipakai,
 * katalog hanya menampilkan yang aktif, hapus ditolak bila dipakai (termasuk kendaraan pelanggan), dan atribut tipe bodi/tahun.
 */
class VehicleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Vehicle::query()->delete();   // migrasi sudah menanam data kendaraan

        $this->actingAs(tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin')), 'web');
    }

    private function vehicle(string $brand, string $model, string $size = 'M', bool $active = true): Vehicle
    {
        return Vehicle::create(['brand' => $brand, 'model' => $model, 'size_category' => $size, 'is_active' => $active]);
    }

    private function customerVehicle(Vehicle $vehicle): CustomerVehicle
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return CustomerVehicle::create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'brand' => $vehicle->brand, 'model' => $vehicle->model,
            'plate' => 'B 1234 ABC', 'plate_normalized' => 'B1234ABC',
        ]);
    }

    public function test_existing_vehicles_default_to_active(): void
    {
        $this->assertTrue($this->vehicle('Honda', 'Civic')->fresh()->is_active);
    }

    public function test_new_attributes_are_saved_and_the_year_range_is_validated(): void
    {
        Livewire::test(CreateVehicle::class)
            ->fillForm(['brand' => 'Toyota', 'model' => 'Innova', 'size_category' => 'L', 'body_type' => 'mpv', 'year_from' => 2016, 'year_to' => 2024, 'notes' => 'Generasi 2'])
            ->call('create')
            ->assertHasNoFormErrors();

        $v = Vehicle::where('model', 'Innova')->firstOrFail();
        $this->assertSame('mpv', $v->body_type);
        $this->assertSame(2016, $v->year_from);
        $this->assertTrue($v->is_active);

        Livewire::test(CreateVehicle::class)
            ->fillForm(['brand' => 'Toyota', 'model' => 'Raize', 'size_category' => 'S', 'year_from' => 2022, 'year_to' => 2020])
            ->call('create')
            ->assertHasFormErrors(['year_to']);
    }

    public function test_deactivating_removes_the_vehicle_from_both_public_catalogs_and_a_brand_with_no_active_vehicle_disappears(): void
    {
        $civic = $this->vehicle('Honda', 'Civic');
        $this->vehicle('Toyota', 'Avanza');

        Livewire::test(ListVehicles::class)->callTableAction('toggle_active', $civic)->assertNotified('Kendaraan dinonaktifkan');
        $this->assertFalse($civic->fresh()->is_active);

        foreach (['/api/quotation/options', '/api/vehicles/catalog'] as $url) {
            $data = $this->getJson($url)->assertSuccessful()->json('data');
            $this->assertNotContains($civic->id, collect($data['vehicles'])->pluck('id')->all());
            $this->assertSame(['Toyota'], $data['brands'], 'Merek tanpa kendaraan aktif tidak ditawarkan.');
        }

        Livewire::test(ListVehicles::class)->removeTableFilter('is_active')->callTableAction('toggle_active', $civic->fresh())->assertNotified('Kendaraan diaktifkan');
        $this->assertTrue($civic->fresh()->is_active);
    }

    public function test_the_list_shows_only_active_by_default_and_can_show_all(): void
    {
        $active = $this->vehicle('Honda', 'Civic');
        $inactive = $this->vehicle('Honda', 'Jazz', 'S', false);

        Livewire::test(ListVehicles::class)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive])
            ->removeTableFilter('is_active')
            ->assertCanSeeTableRecords([$active, $inactive]);
    }

    public function test_a_vehicle_used_by_a_customer_vehicle_cannot_be_deleted_and_the_message_points_to_deactivation(): void
    {
        $vehicle = $this->vehicle('Honda', 'Civic');
        $this->customerVehicle($vehicle);

        Livewire::test(ListVehicles::class)->callTableAction('delete', $vehicle)->assertNotified('Tidak bisa menghapus kendaraan ini');
        Livewire::test(EditVehicle::class, ['record' => $vehicle->getRouteKey()])->callAction('delete')->assertNotified('Tidak bisa menghapus kendaraan ini');

        $this->assertNotNull(Vehicle::find($vehicle->id));
        $this->assertSame($vehicle->id, CustomerVehicle::first()->vehicle_id, 'Tautan katalog di kendaraan pelanggan tetap utuh.');
        $this->assertSame(['kendaraan pelanggan' => 1], $vehicle->usageSummary());
    }

    public function test_an_unused_vehicle_can_still_be_deleted_and_bulk_delete_skips_used_ones(): void
    {
        $unused = $this->vehicle('Honda', 'Brio', 'S');
        $used = $this->vehicle('Honda', 'Civic');
        $this->customerVehicle($used);

        Livewire::test(ListVehicles::class)->callTableAction('delete', $unused)->assertNotified('Kendaraan dihapus');
        $this->assertNull(Vehicle::find($unused->id));

        $another = $this->vehicle('Honda', 'City', 'S');
        Livewire::test(ListVehicles::class)
            ->callTableBulkAction('delete', [$used, $another])
            ->assertNotified('1 kendaraan dihapus, 1 tidak bisa dihapus');
        $this->assertNotNull(Vehicle::find($used->id));
        $this->assertNull(Vehicle::find($another->id));
    }

    public function test_bulk_deactivate_and_size_examples_only_from_active_vehicles(): void
    {
        $a = $this->vehicle('Honda', 'Brio', 'S');
        $b = $this->vehicle('Toyota', 'Agya', 'S');
        $this->vehicle('Daihatsu', 'Sirion', 'S', false);

        $examples = Vehicle::sizeExamples();
        $this->assertSame(['Honda Brio', 'Toyota Agya'], $examples['S']);
        $this->assertSame([], $examples['XL']);

        Livewire::test(ListVehicles::class)->callTableBulkAction('deactivate', [$a, $b])->assertNotified('2 kendaraan dinonaktifkan');
        $this->assertSame(0, Vehicle::active()->count());
    }

    public function test_the_size_helper_shows_registered_examples(): void
    {
        $this->vehicle('Honda', 'Brio', 'S');

        Livewire::test(CreateVehicle::class)->assertSee('Contoh yang sudah terdaftar')->assertSee('Honda Brio');
    }
}

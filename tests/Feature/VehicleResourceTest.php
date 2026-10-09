<?php

namespace Tests\Feature;

use App\Filament\Resources\VehicleResource;
use App\Filament\Resources\VehicleResource\Pages\CreateVehicle;
use App\Filament\Resources\VehicleResource\Pages\EditVehicle;
use App\Filament\Resources\VehicleResource\Pages\ListVehicles;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kendaraan (master data nasional): hak akses, daftar + pencarian + filter ukuran, tambah / ubah dengan pencegahan duplikat
 * merek+model+varian (termasuk varian kosong dan spasi tepi), validasi, hapus satuan / dari halaman ubah / massal
 * (kendaraan yang masih dipakai quotation ditolak dengan pesan jelas) dan jejak aktivitas.
 */
class VehicleResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // Migrasi sudah menanam data kendaraan; hapus supaya tes menghitung hanya data miliknya.
        Vehicle::query()->delete();
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function user(string $role, ?array $menuAccess = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function vehicle(string $brand, ?string $model = 'Civic', ?string $variant = null, string $size = 'M'): Vehicle
    {
        return Vehicle::create(['brand' => $brand, 'model' => $model, 'variant' => $variant, 'size_category' => $size]);
    }

    private function quote(Vehicle $vehicle): Quotation
    {
        return Quotation::create(['quotation_number' => 'QTN-T-' . uniqid(), 'vehicle_id' => $vehicle->id, 'customer_name' => 'Budi', 'status' => 'new']);
    }

    // ------------------------------------------------------------- akses

    public function test_access_rules(): void
    {
        $record = new Vehicle();

        $this->as($this->user('super_admin'));
        $this->assertTrue(VehicleResource::canViewAny());
        $this->assertTrue(VehicleResource::canCreate());
        $this->assertTrue(VehicleResource::canEdit($record));
        $this->assertTrue(VehicleResource::canDelete($record));
        $this->assertTrue(VehicleResource::canDeleteAny());

        $this->as($this->user('kasir'));
        $this->assertTrue(VehicleResource::canViewAny());
        $this->assertTrue(VehicleResource::canCreate());
        $this->assertTrue(VehicleResource::canEdit($record));
        $this->assertTrue(VehicleResource::canDelete($record));

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(VehicleResource::canViewAny());
        $this->assertFalse(VehicleResource::canCreate());
        $this->assertFalse(VehicleResource::canEdit($record));
        $this->assertFalse(VehicleResource::canDelete($record));
        $this->assertFalse(VehicleResource::canDeleteAny());
    }

    // ------------------------------------------------------------- daftar

    public function test_list_is_ordered_by_brand_and_searchable_and_filterable(): void
    {
        $toyota = $this->vehicle('Toyota', 'Alphard', '2.5 G AT', 'XL');
        $honda = $this->vehicle('Honda', 'Civic', '1.5 RS CVT', 'M');
        $suzuki = $this->vehicle('Suzuki', 'Ignis', null, 'S');

        $this->as($this->user('kasir'));
        Livewire::test(ListVehicles::class)
            ->assertCanSeeTableRecords([$honda, $suzuki, $toyota], inOrder: true)
            ->assertTableColumnStateSet('size_category', 'XL', record: $toyota)
            ->assertTableColumnStateSet('variant', null, record: $suzuki);

        Livewire::test(ListVehicles::class)->searchTable('Alphard')->assertCanSeeTableRecords([$toyota])->assertCanNotSeeTableRecords([$honda, $suzuki]);
        Livewire::test(ListVehicles::class)->searchTable('RS CVT')->assertCanSeeTableRecords([$honda])->assertCanNotSeeTableRecords([$toyota]);
        Livewire::test(ListVehicles::class)->filterTable('size_category', 'S')->assertCanSeeTableRecords([$suzuki])->assertCanNotSeeTableRecords([$honda, $toyota]);
    }

    // ------------------------------------------------------------- tambah

    public function test_create_a_vehicle_and_log_it(): void
    {
        $user = $this->as($this->user('kasir'));

        Livewire::test(CreateVehicle::class)
            ->fillForm(['brand' => 'Honda', 'model' => 'Civic', 'variant' => '1.5 RS CVT', 'size_category' => 'M'])
            ->call('create')
            ->assertHasNoFormErrors();

        $vehicle = Vehicle::firstOrFail();
        $this->assertSame(['Honda', 'Civic', '1.5 RS CVT', 'M'], [$vehicle->brand, $vehicle->model, $vehicle->variant, $vehicle->size_category]);
        $this->assertSame($user->id, Activity::where('log_name', 'vehicle')->latest('id')->firstOrFail()->causer_id);
    }

    public function test_model_and_variant_are_optional(): void
    {
        $this->as($this->user('kasir'));

        Livewire::test(CreateVehicle::class)
            ->fillForm(['brand' => 'Tesla', 'model' => null, 'variant' => null, 'size_category' => 'L'])
            ->call('create')
            ->assertHasNoFormErrors();

        $vehicle = Vehicle::firstOrFail();
        $this->assertNull($vehicle->model);
        $this->assertNull($vehicle->variant);
    }

    public function test_a_duplicate_with_a_filled_variant_is_rejected_by_the_form(): void
    {
        $this->vehicle('Honda', 'Civic', '1.5 RS CVT');
        $this->as($this->user('kasir'));

        Livewire::test(CreateVehicle::class)
            ->fillForm(['brand' => 'Honda', 'model' => 'Civic', 'variant' => '1.5 RS CVT', 'size_category' => 'M'])
            ->call('create')
            ->assertHasFormErrors(['variant' => 'unique']);

        $this->assertSame(1, Vehicle::count());
    }

    public function test_a_duplicate_with_an_empty_variant_is_still_caught(): void
    {
        $this->vehicle('Honda', 'Civic', null);
        $this->as($this->user('kasir'));

        Livewire::test(CreateVehicle::class)
            ->fillForm(['brand' => 'Honda', 'model' => 'Civic', 'variant' => null, 'size_category' => 'M'])
            ->call('create')
            ->assertNotified('Kendaraan sudah terdaftar');

        $this->assertSame(1, Vehicle::count());
    }

    public function test_edge_spaces_do_not_hide_a_duplicate_and_are_not_stored(): void
    {
        $this->vehicle('Honda', 'Civic', null);
        $this->as($this->user('kasir'));

        Livewire::test(CreateVehicle::class)
            ->fillForm(['brand' => ' Honda ', 'model' => '  Civic', 'variant' => '   ', 'size_category' => 'M'])
            ->call('create')
            ->assertNotified('Kendaraan sudah terdaftar');
        $this->assertSame(1, Vehicle::count());

        Livewire::test(CreateVehicle::class)
            ->fillForm(['brand' => '  Mazda ', 'model' => ' CX-5 ', 'variant' => '  ', 'size_category' => 'L'])
            ->call('create')
            ->assertHasNoFormErrors();

        $mazda = Vehicle::where('brand', 'Mazda')->firstOrFail();
        $this->assertSame('CX-5', $mazda->model);
        $this->assertNull($mazda->variant, 'Isian spasi-saja disimpan sebagai kosong.');
    }

    public function test_create_validation(): void
    {
        $this->as($this->user('kasir'));
        $valid = ['brand' => 'Honda', 'model' => 'Civic', 'variant' => null, 'size_category' => 'M'];

        Livewire::test(CreateVehicle::class)->fillForm(array_merge($valid, ['brand' => '']))->call('create')->assertHasFormErrors(['brand' => 'required']);
        Livewire::test(CreateVehicle::class)->fillForm(array_merge($valid, ['brand' => str_repeat('a', 256)]))->call('create')->assertHasFormErrors(['brand' => 'max']);
        Livewire::test(CreateVehicle::class)->fillForm(array_merge($valid, ['size_category' => null]))->call('create')->assertHasFormErrors(['size_category' => 'required']);
        Livewire::test(CreateVehicle::class)->fillForm(array_merge($valid, ['size_category' => 'XXXL']))->call('create')->assertHasFormErrors(['size_category']);

        $this->assertSame(0, Vehicle::count());
    }

    // ------------------------------------------------------------- ubah

    public function test_edit_updates_a_vehicle_without_clashing_with_itself(): void
    {
        $vehicle = $this->vehicle('Honda', 'Civic', null, 'M');
        $this->as($this->user('kasir'));

        Livewire::test(EditVehicle::class, ['record' => $vehicle->getRouteKey()])
            ->fillForm(['size_category' => 'L'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('L', $vehicle->fresh()->size_category);
    }

    public function test_edit_cannot_turn_a_vehicle_into_a_duplicate(): void
    {
        $this->vehicle('Honda', 'Civic', null);
        $other = $this->vehicle('Honda', 'Jazz', null);
        $this->as($this->user('kasir'));

        Livewire::test(EditVehicle::class, ['record' => $other->getRouteKey()])
            ->fillForm(['model' => 'Civic'])
            ->call('save')
            ->assertNotified('Kendaraan sudah terdaftar');

        $this->assertSame('Jazz', $other->fresh()->model);
    }

    // ------------------------------------------------------------- hapus

    public function test_an_unused_vehicle_is_deleted_from_the_list_and_from_the_edit_page(): void
    {
        $a = $this->vehicle('Honda', 'Civic');
        $b = $this->vehicle('Toyota', 'Raize');
        $this->as($this->user('kasir'));

        Livewire::test(ListVehicles::class)->callTableAction('delete', $a)->assertNotified('Kendaraan dihapus');
        $this->assertNull(Vehicle::find($a->id));

        Livewire::test(EditVehicle::class, ['record' => $b->getRouteKey()])->callAction('delete')->assertNotified('Kendaraan dihapus');
        $this->assertNull(Vehicle::find($b->id));
    }

    public function test_a_vehicle_used_by_a_quotation_cannot_be_deleted_from_either_place(): void
    {
        $vehicle = $this->vehicle('Honda', 'Civic');
        $this->quote($vehicle);
        $this->as($this->user('kasir'));

        Livewire::test(ListVehicles::class)
            ->callTableAction('delete', $vehicle)
            ->assertNotified('Tidak bisa menghapus kendaraan ini');

        Livewire::test(EditVehicle::class, ['record' => $vehicle->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Tidak bisa menghapus kendaraan ini');

        $this->assertNotNull(Vehicle::find($vehicle->id));
    }

    public function test_bulk_delete_removes_unused_vehicles_and_skips_the_used_ones(): void
    {
        $free = $this->vehicle('Honda', 'Civic');
        $used = $this->vehicle('Toyota', 'Raize');
        $this->quote($used);
        $this->as($this->user('kasir'));

        Livewire::test(ListVehicles::class)
            ->callTableBulkAction('delete', [$free, $used])
            ->assertNotified('1 kendaraan dihapus, 1 tidak bisa dihapus');

        $this->assertNull(Vehicle::find($free->id));
        $this->assertNotNull(Vehicle::find($used->id));

        Livewire::test(ListVehicles::class)
            ->callTableBulkAction('delete', [$used])
            ->assertNotified('Tidak ada kendaraan yang bisa dihapus');
    }

    public function test_bulk_delete_is_hidden_without_menu_access_to_delete(): void
    {
        $this->vehicle('Honda', 'Civic');
        $this->as($this->user('kasir'));

        Livewire::test(ListVehicles::class)->assertTableBulkActionVisible('delete');
    }
}

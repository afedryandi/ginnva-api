<?php

namespace Tests\Feature;

use App\Filament\Resources\VehicleGlassAreaResource;
use App\Filament\Resources\VehicleGlassAreaResource\Pages\CreateVehicleGlassArea;
use App\Filament\Resources\VehicleGlassAreaResource\Pages\ListVehicleGlassAreas;
use App\Filament\Resources\VehicleResource;
use App\Filament\Resources\VehicleResource\Pages\CreateVehicle;
use App\Filament\Resources\VehicleResource\Pages\EditVehicle;
use App\Filament\Resources\VehicleResource\Pages\ListVehicles;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGlassArea;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Master Data Fase 4 (2026-10-21): pemilik data = akses penuh. Staf dengan akses menu hanya MELIHAT kendaraan dan luas kaca; mengubah
 * (tambah, ubah, nonaktifkan, hapus) butuh akses penuh atau izin aksi eksplisit di Hak Akses Detail. Impor dari Google Sheet hanya akses penuh.
 */
class MasterDataOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Vehicle::query()->delete();
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->vehicle = Vehicle::create(['brand' => 'Honda', 'model' => 'Civic', 'size_category' => 'M']);
    }

    private function staff(?array $permissions = null): User
    {
        $user = User::create([
            'name' => 'Kasir ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id,
            'is_active' => true, 'menu_access' => null, 'menu_permissions' => $permissions,
        ]);
        $user->assignRole('kasir');

        return $user;
    }

    private function admin(): User
    {
        return tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
    }

    public function test_staff_without_a_grant_can_view_vehicles_but_not_change_them(): void
    {
        $this->actingAs($this->staff(), 'web');

        $this->assertTrue(VehicleResource::canViewAny());
        $this->assertFalse(VehicleResource::canCreate());
        $this->assertFalse(VehicleResource::canEdit($this->vehicle));
        $this->assertFalse(VehicleResource::canDelete($this->vehicle));

        Livewire::test(ListVehicles::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->vehicle])
            ->assertTableActionHidden('toggle_active', $this->vehicle)
            ->assertTableActionHidden('delete', $this->vehicle);

        Livewire::test(CreateVehicle::class)->assertForbidden();
        Livewire::test(EditVehicle::class, ['record' => $this->vehicle->getRouteKey()])->assertForbidden();
    }

    public function test_an_explicit_update_grant_allows_editing_and_deactivating_but_not_creating_or_deleting(): void
    {
        $this->actingAs($this->staff([VehicleResource::class => ['update']]), 'web');

        $this->assertTrue(VehicleResource::canEdit($this->vehicle));
        $this->assertFalse(VehicleResource::canCreate());
        $this->assertFalse(VehicleResource::canDelete($this->vehicle));

        Livewire::test(ListVehicles::class)
            ->assertTableActionVisible('toggle_active', $this->vehicle)
            ->assertTableActionHidden('delete', $this->vehicle)
            ->callTableAction('toggle_active', $this->vehicle)
            ->assertNotified('Kendaraan dinonaktifkan');
        $this->assertFalse($this->vehicle->fresh()->is_active);
    }

    public function test_full_access_can_always_change_vehicles(): void
    {
        $this->actingAs($this->admin(), 'web');

        $this->assertTrue(VehicleResource::canCreate());
        $this->assertTrue(VehicleResource::canEdit($this->vehicle));
        $this->assertTrue(VehicleResource::canDelete($this->vehicle));
        Livewire::test(CreateVehicle::class)->assertSuccessful();
    }

    public function test_glass_areas_follow_the_same_rule(): void
    {
        $area = VehicleGlassArea::create(['brand' => 'Honda', 'model' => 'Civic', 'front_sqm' => 1]);

        $this->actingAs($this->staff(), 'web');
        $this->assertTrue(VehicleGlassAreaResource::canViewAny());
        $this->assertFalse(VehicleGlassAreaResource::canCreate());
        $this->assertFalse(VehicleGlassAreaResource::canEdit($area));
        $this->assertFalse(VehicleGlassAreaResource::canDelete($area));
        Livewire::test(CreateVehicleGlassArea::class)->assertForbidden();
        Livewire::test(ListVehicleGlassAreas::class)->assertSuccessful()->assertSee('Honda');

        $this->actingAs($this->staff([VehicleGlassAreaResource::class => ['create', 'update']]), 'web');
        $this->assertTrue(VehicleGlassAreaResource::canCreate());
        $this->assertTrue(VehicleGlassAreaResource::canEdit($area));
        $this->assertFalse(VehicleGlassAreaResource::canDelete($area));
        Livewire::test(CreateVehicleGlassArea::class)->assertSuccessful();
    }

    public function test_the_sheet_import_stays_full_access_only_even_with_grants(): void
    {
        $this->actingAs($this->staff([VehicleGlassAreaResource::class => ['create', 'update', 'delete']]), 'web');

        Livewire::test(ListVehicleGlassAreas::class)->assertActionHidden('import_from_sheet');
    }
}

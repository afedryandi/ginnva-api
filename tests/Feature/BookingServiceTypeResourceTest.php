<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingServiceTypeResource;
use App\Filament\Resources\BookingServiceTypeResource\Pages\EditBookingServiceType;
use App\Filament\Resources\BookingServiceTypeResource\Pages\ListBookingServiceTypes;
use App\Models\Booking;
use App\Models\BookingServiceType;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Menu "Layanan Booking" (Fase 2 pengerjaan ulang booking, 2026-10-16): admin mengubah nama, deskripsi, kategori kapasitas,
 * lama pengerjaan, urutan, dan aktif/tidak untuk empat layanan; perubahan langsung terbaca API katalog dan booking baru.
 */
class BookingServiceTypeResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function actAs(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $store = Store::firstOrCreate(['name' => 'Toko A'], ['city' => 'Jakarta', 'address' => 'Jl. A', 'is_active' => true]);
        $user = User::create(['name' => ucfirst($role), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'menu_access' => null, 'is_active' => true, 'join_date' => '2025-01-01']);
        $user->assignRole($role);
        $this->actingAs($user, 'web');

        return $user;
    }

    public function test_the_four_default_services_are_seeded_by_the_migration(): void
    {
        $services = BookingServiceType::catalog()->keyBy('key');

        $this->assertSame(['kaca_film', 'ppf', 'detailing', 'premium_wash'], $services->keys()->all());
        $this->assertSame('berat', $services['ppf']->capacity_category);
        $this->assertSame('ringan', $services['premium_wash']->capacity_category);
        $this->assertSame(3, $services['ppf']->estimated_duration_days);
    }

    public function test_list_page_shows_the_services_and_has_no_create_or_delete(): void
    {
        $this->actAs('super_admin');

        Livewire::test(ListBookingServiceTypes::class)
            ->assertSuccessful()
            ->assertSee('Pelindung Cat (PPF)')
            ->assertSee('Premium Wash');

        $this->assertFalse(BookingServiceTypeResource::canCreate());
        $this->assertFalse(BookingServiceTypeResource::canDeleteAny());
        $this->assertFalse(BookingServiceTypeResource::canDelete(BookingServiceType::first()));
    }

    public function test_full_access_can_edit_and_the_change_reaches_the_catalog_and_new_bookings(): void
    {
        $this->actAs('super_admin');
        $ppf = BookingServiceType::where('key', 'ppf')->firstOrFail();

        Livewire::test(EditBookingServiceType::class, ['record' => $ppf->getKey()])
            ->fillForm(['label' => 'Paint Protection Film', 'description' => 'Pelindung cat premium.', 'estimated_duration_days' => 4, 'sort_order' => 9])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Paint Protection Film', $ppf->fresh()->label);

        $catalog = collect($this->getJson('/api/booking/services')->assertOk()->json('data.services'))->keyBy('key');
        $this->assertSame('Paint Protection Film', $catalog['ppf']['label']);
        $this->assertSame('Pelindung cat premium.', $catalog['ppf']['description']);
        $this->assertSame(4, $catalog['ppf']['estimated_duration_days']);
        $this->assertSame('ppf', $catalog->keys()->last(), 'Urutan mengikuti sort_order.');

        $store = Store::first();
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $vehicleId = $this->actingAs($customer, 'customer')->postJson('/api/customer/vehicles', ['brand' => 'Toyota', 'model' => 'Avanza', 'plate' => 'B 1234 ABC'])->json('data.id');

        $id = $this->actingAs($customer, 'customer')->postJson('/api/customer/bookings', [
            'store_id' => $store->id, 'services' => ['ppf'], 'customer_vehicle_id' => $vehicleId, 'preferred_date' => now()->addDays(5)->toDateString(),
        ])->assertStatus(201)->json('data.id');

        $this->assertSame('Paint Protection Film', Booking::findOrFail($id)->service_type);
    }

    public function test_form_validation_rejects_empty_names_and_out_of_range_durations(): void
    {
        $this->actAs('super_admin');
        $detailing = BookingServiceType::where('key', 'detailing')->firstOrFail();

        Livewire::test(EditBookingServiceType::class, ['record' => $detailing->getKey()])
            ->fillForm(['label' => '', 'estimated_duration_days' => 0])
            ->call('save')
            ->assertHasFormErrors(['label', 'estimated_duration_days']);

        Livewire::test(EditBookingServiceType::class, ['record' => $detailing->getKey()])
            ->fillForm(['estimated_duration_days' => 15])
            ->call('save')
            ->assertHasFormErrors(['estimated_duration_days']);
    }

    public function test_a_store_manager_can_view_but_not_edit_the_catalog(): void
    {
        $this->actAs('store_manager');

        $this->assertTrue(BookingServiceTypeResource::canViewAny());
        $this->assertFalse(BookingServiceTypeResource::canEdit(BookingServiceType::first()));
    }

    public function test_the_key_cannot_be_changed_from_the_form(): void
    {
        $this->actAs('super_admin');
        $ppf = BookingServiceType::where('key', 'ppf')->firstOrFail();

        Livewire::test(EditBookingServiceType::class, ['record' => $ppf->getKey()])
            ->fillForm(['key' => 'hacked'])
            ->call('save');

        $this->assertSame('ppf', $ppf->fresh()->key);
    }
}

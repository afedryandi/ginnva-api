<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\BookingResource\Pages\EditBooking;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Booking yang sudah dikonfirmasi: data inti terkunci di form Ubah; alur kerja dan jadwal ulang tetap jalan. */
class BookingConfirmedLockTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountSeeder::class);
        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true,
            'install_capacity_per_day' => 5, 'light_capacity_per_slot' => 1,
            'opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'open' => '09:00', 'close' => '18:00']],
        ]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
    }

    private function booking(string $status): Booking
    {
        return Booking::create([
            'booking_number' => 'BKG-LK-' . uniqid(),
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'vehicle_plate' => 'B 1234 ABC',
            'preferred_date' => now()->addDays(4)->toDateString(),
            'status' => $status,
        ]);
    }

    public function test_core_helper_locks_only_confirmed_bookings(): void
    {
        $this->assertTrue(BookingResource::coreLocked($this->booking('confirmed')));
        $this->assertFalse(BookingResource::coreLocked($this->booking('pending')));
        $this->assertFalse(BookingResource::coreLocked(null));
    }

    public function test_a_tampered_submit_cannot_change_core_data_of_a_confirmed_booking(): void
    {
        $booking = $this->booking('confirmed');

        Livewire::test(EditBooking::class, ['record' => $booking->getKey()])
            ->set('data.vehicle_plate', 'ZZ 9999 ZZ')
            ->set('data.notes', 'catatan baru')
            ->call('save');

        $fresh = $booking->fresh();
        $this->assertSame('B 1234 ABC', $fresh->vehicle_plate);
        $this->assertSame('catatan baru', $fresh->notes);
    }

    public function test_full_access_can_unlock_with_a_reason(): void
    {
        $booking = $this->booking('confirmed');

        Livewire::test(EditBooking::class, ['record' => $booking->getKey()])
            ->set('data.core_unlocked', true)
            ->set('data.unlock_reason', 'Salah ketik plat')
            ->set('data.vehicle_plate', 'B 5555 XYZ')
            ->call('save');

        $this->assertSame('B 5555 XYZ', $booking->fresh()->vehicle_plate);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $booking->id, 'log_name' => 'booking']);
    }

    public function test_a_pending_booking_is_still_fully_editable(): void
    {
        $booking = $this->booking('pending');

        Livewire::test(EditBooking::class, ['record' => $booking->getKey()])
            ->set('data.vehicle_plate', 'D 1 AAA')
            ->call('save');

        $this->assertSame('D 1 AAA', $booking->fresh()->vehicle_plate);
    }
}

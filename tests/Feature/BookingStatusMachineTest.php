<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Aturan status booking (keputusan user 2026-10-02): pending -> confirmed ->
 * completed, batal dari pending/confirmed, completed & cancelled FINAL.
 * Dijaga di model (Booking::STATUS_TRANSITIONS + guard 'updating').
 */
class BookingStatusMachineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Observer/notifikasi memanggil User::role('super_admin'|'direksi');
        // Spatie melempar RoleDoesNotExist kalau role belum ada di DB test.
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('direksi', 'web');
    }

    private function makeBooking(string $status = 'pending'): Booking
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000' . random_int(100, 999)]);

        return Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id'    => $customer->id,
            'store_id'       => $store->id,
            'service_type'   => 'PPF',
            'product_ppf'    => true,
            'preferred_date' => now()->addDays(10)->toDateString(),
            'status'         => $status,
        ]);
    }

    public function test_transition_matrix(): void
    {
        $this->assertTrue(Booking::canTransition('pending', 'confirmed'));
        $this->assertTrue(Booking::canTransition('pending', 'cancelled'));
        $this->assertTrue(Booking::canTransition('confirmed', 'completed'));
        $this->assertTrue(Booking::canTransition('confirmed', 'cancelled'));
        $this->assertTrue(Booking::canTransition('pending', 'pending'));

        $this->assertFalse(Booking::canTransition('pending', 'completed'));
        $this->assertFalse(Booking::canTransition('confirmed', 'pending'));
        $this->assertFalse(Booking::canTransition('completed', 'cancelled'));
        $this->assertFalse(Booking::canTransition('cancelled', 'confirmed'));
    }

    public function test_illegal_status_update_throws(): void
    {
        $booking = $this->makeBooking('pending');

        $this->expectException(\DomainException::class);
        $booking->update(['status' => 'completed']);
    }

    public function test_final_status_cannot_change(): void
    {
        $booking = $this->makeBooking('pending');
        $booking->update(['status' => 'confirmed']);
        $booking->update(['status' => 'completed']);

        $this->expectException(\DomainException::class);
        $booking->update(['status' => 'cancelled']);
    }

    public function test_completed_sets_current_stage(): void
    {
        $booking = $this->makeBooking('pending');
        $booking->update(['status' => 'confirmed']);
        $booking->update(['status' => 'completed']);

        $this->assertSame('completed', $booking->fresh()->current_stage);
    }

    public function test_cancel_with_records_structured_reason(): void
    {
        $booking = $this->makeBooking('confirmed');

        $booking->cancelWith('staff', null, '  Customer minta batal  ');
        $booking = $booking->fresh();

        $this->assertSame('cancelled', $booking->status);
        $this->assertSame('Customer minta batal', $booking->cancel_reason);
        $this->assertSame('staff', $booking->cancelled_by_type);
        $this->assertNotNull($booking->cancelled_at);
    }

    public function test_cancel_with_blank_reason_stores_null(): void
    {
        $booking = $this->makeBooking('pending');

        $booking->cancelWith('customer', null, '   ');

        $this->assertNull($booking->fresh()->cancel_reason);
    }
}

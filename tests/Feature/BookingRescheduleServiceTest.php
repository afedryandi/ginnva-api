<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingCancellationRequest;
use App\Models\BookingRescheduleRequest;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingCancellationService;
use App\Services\BookingRescheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use RuntimeException;
use Tests\TestCase;

/**
 * Jadwal ulang (staff langsung / pengajuan customer) dan pengajuan
 * pembatalan booking confirmed -- lihat BookingRescheduleService &
 * BookingCancellationService.
 */
class BookingRescheduleServiceTest extends TestCase
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

    // decided_by adalah FK ke users: butuh user nyata.
    private function makeStaff(): User
    {
        return User::create(['name' => 'Staff ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x']);
    }

    private function makeBooking(string $status = 'pending', array $overrides = []): Booking
    {
        $store = Store::create(['city' => 'Jakarta', 'name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000' . random_int(100, 999)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id'    => $customer->id,
            'store_id'       => $store->id,
            'service_type'   => 'Kaca Film (Window Film)',
            'product_kaca_film' => true,
            'preferred_date' => now()->addDays(10)->toDateString(),
            'status'         => $status,
        ], $overrides));
    }

    public function test_apply_moves_date_of_pending_booking(): void
    {
        $booking = $this->makeBooking('pending');
        $newDate = now()->addDays(12);

        $updated = app(BookingRescheduleService::class)->apply($booking, $newDate);

        $this->assertSame($newDate->toDateString(), $updated->preferred_date->toDateString());
    }

    public function test_apply_rejects_same_date(): void
    {
        $booking = $this->makeBooking('pending');

        $this->expectException(RuntimeException::class);
        app(BookingRescheduleService::class)->apply($booking, now()->addDays(10));
    }

    public function test_apply_rejects_past_date(): void
    {
        $booking = $this->makeBooking('pending');

        $this->expectException(RuntimeException::class);
        app(BookingRescheduleService::class)->apply($booking, now()->subDays(2));
    }

    public function test_apply_rejects_when_work_already_started(): void
    {
        $booking = $this->makeBooking('confirmed', ['current_stage' => 'kf_cleaning']);

        $this->expectException(RuntimeException::class);
        app(BookingRescheduleService::class)->apply($booking, now()->addDays(12));
    }

    public function test_apply_rejects_final_status(): void
    {
        $booking = $this->makeBooking('pending');
        $booking->update(['status' => 'cancelled']);

        $this->expectException(RuntimeException::class);
        app(BookingRescheduleService::class)->apply($booking->fresh(), now()->addDays(12));
    }

    public function test_apply_closes_other_pending_requests(): void
    {
        $booking = $this->makeBooking('pending');
        $request = BookingRescheduleRequest::create([
            'booking_id'     => $booking->id,
            'customer_id'    => $booking->customer_id,
            'requested_date' => now()->addDays(15)->toDateString(),
            'status'         => 'pending',
        ]);

        app(BookingRescheduleService::class)->apply($booking, now()->addDays(12));

        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_approve_applies_requested_date_and_marks_request(): void
    {
        $booking = $this->makeBooking('pending');
        $request = BookingRescheduleRequest::create([
            'booking_id'     => $booking->id,
            'customer_id'    => $booking->customer_id,
            'requested_date' => now()->addDays(14)->toDateString(),
            'status'         => 'pending',
        ]);

        $updated = app(BookingRescheduleService::class)->approve($request, $this->makeStaff()->id, 'ok');

        $this->assertSame(now()->addDays(14)->toDateString(), $updated->preferred_date->toDateString());
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_pending_requests_are_closed_when_booking_is_cancelled(): void
    {
        $booking = $this->makeBooking('pending');
        $request = BookingRescheduleRequest::create([
            'booking_id'     => $booking->id,
            'customer_id'    => $booking->customer_id,
            'requested_date' => now()->addDays(14)->toDateString(),
            'status'         => 'pending',
        ]);

        $booking->cancelWith('customer', $booking->customer_id, null);

        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_cancellation_request_approve_cancels_booking_with_customer_reason(): void
    {
        $booking = $this->makeBooking('confirmed');
        $request = BookingCancellationRequest::create([
            'booking_id'  => $booking->id,
            'customer_id' => $booking->customer_id,
            'reason'      => 'Berhalangan hadir',
            'status'      => 'pending',
        ]);

        $updated = app(BookingCancellationService::class)->approve($request, $this->makeStaff()->id, null);

        $this->assertSame('cancelled', $updated->status);
        $this->assertSame('Berhalangan hadir', $updated->cancel_reason);
        $this->assertSame('customer', $updated->cancelled_by_type);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_cancellation_request_cannot_be_decided_twice(): void
    {
        $booking = $this->makeBooking('confirmed');
        $request = BookingCancellationRequest::create([
            'booking_id'  => $booking->id,
            'customer_id' => $booking->customer_id,
            'reason'      => 'Berhalangan hadir',
            'status'      => 'pending',
        ]);

        app(BookingCancellationService::class)->reject($request, $this->makeStaff()->id, 'Silakan datang sesuai jadwal');

        $this->assertSame('rejected', $request->fresh()->status);

        $this->expectException(RuntimeException::class);
        app(BookingCancellationService::class)->approve($request->fresh(), $this->makeStaff()->id, null);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRescheduleRequest;
use App\Models\Customer;
use App\Models\Store;
use App\Services\BookingRescheduleService;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Aturan tahap (QC wajib, urutan, "sudah dimulai") dan pengingat (H-1, SLA
 * pengajuan customer) -- ronde audit 6-7 booking.
 */
class BookingStageAndReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('direksi', 'web');
    }

    private function makeBooking(array $overrides = []): Booking
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000' . random_int(100, 999)]);

        return Booking::create(array_merge([
            'booking_number'    => 'BKG-TEST-' . uniqid(),
            'customer_id'       => $customer->id,
            'store_id'          => $store->id,
            'service_type'      => 'PPF',
            'product_ppf'       => true,
            'preferred_date'    => now()->addDays(10)->toDateString(),
            'status'            => 'confirmed',
        ], $overrides));
    }

    public function test_completion_requires_quality_check_stage(): void
    {
        $booking = $this->makeBooking(['current_stage' => 'ppf_installation']);
        $this->assertNotNull($booking->completionBlocker());

        $booking->update(['current_stage' => 'qc']);
        $this->assertNull($booking->fresh()->completionBlocker());
    }

    public function test_stage_sequence_per_product_combination(): void
    {
        $ppfOnly = $this->makeBooking(['product_ppf' => true, 'product_kaca_film' => false]);
        $this->assertSame(
            ['ppf_washing', 'ppf_detailing', 'ppf_installation', 'qc', 'completed'],
            $ppfOnly->stageSequenceFor('current_stage')
        );

        $both = $this->makeBooking(['product_ppf' => true, 'product_kaca_film' => true]);
        $this->assertSame(['kf_cleaning', 'kf_heating', 'kf_installation', 'qc', 'completed'], $both->stageSequenceFor('current_stage'));
        $this->assertSame(['ppf_washing', 'ppf_detailing', 'ppf_installation'], $both->stageSequenceFor('secondary_stage'));
    }

    public function test_work_started_counts_secondary_stage_for_two_product_booking(): void
    {
        $both = $this->makeBooking(['product_ppf' => true, 'product_kaca_film' => true]);
        $this->assertFalse($both->hasWorkStarted());

        $both->update(['secondary_stage' => 'ppf_washing']);
        $this->assertTrue($both->fresh()->hasWorkStarted());
    }

    public function test_h1_reminder_is_sent_once_and_flag_set(): void
    {
        $booking = $this->makeBooking(['preferred_date' => now()->addDay()->toDateString()]);

        $push = $this->mock(PushNotificationService::class);
        $push->shouldReceive('sendToCustomer')->once();
        $push->shouldReceive('sendToUsers')->never();

        $this->artisan('bookings:daily-reminders', ['--h1-only' => true])->assertSuccessful();
        $this->assertNotNull($booking->fresh()->h1_reminder_sent_at);

        // Run kedua tidak mengirim ulang (mock `once()` akan gagal kalau terkirim lagi).
        $this->artisan('bookings:daily-reminders', ['--h1-only' => true])->assertSuccessful();
    }

    public function test_reschedule_resets_h1_reminder_flag(): void
    {
        $booking = $this->makeBooking(['preferred_date' => now()->addDays(5)->toDateString()]);
        DB::table('bookings')->where('id', $booking->id)->update(['h1_reminder_sent_at' => now()]);

        app(BookingRescheduleService::class)->apply($booking->fresh(), now()->addDays(8));

        $this->assertNull($booking->fresh()->h1_reminder_sent_at);
    }

    public function test_pending_customer_request_gets_sla_reminder_and_count(): void
    {
        $booking = $this->makeBooking(['status' => 'pending']);
        $request = BookingRescheduleRequest::create([
            'booking_id'     => $booking->id,
            'customer_id'    => $booking->customer_id,
            'requested_date' => now()->addDays(12)->toDateString(),
            'status'         => 'pending',
        ]);
        DB::table('booking_reschedule_requests')->where('id', $request->id)->update(['created_at' => now()->subHours(5)]);

        $push = $this->mock(PushNotificationService::class);
        $push->shouldReceive('sendToUsers')->once();

        $this->artisan('bookings:remind-pending-requests')->assertSuccessful();

        $fresh = $request->fresh();
        $this->assertSame(1, (int) $fresh->reminder_count);
        $this->assertNotNull($fresh->reminder_sent_at);
    }
}

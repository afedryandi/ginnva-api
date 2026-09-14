<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Services\BookingPostingService;
use App\Services\RefundService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Audit framework 2026-09-14, "Cakupan automated testing" -- RefundService
 * juga sekaligus regression-test untuk perbaikan race condition
 * "Integritas transaksi finansial" (lockForUpdate di dalam transaction,
 * validasi sisa refund pakai data terkunci) yang dikerjakan sesi ini.
 */
class RefundServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
    }

    private function makePostedBooking(float $amount): Booking
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000001']);

        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'completed',
            'transaction_amount' => $amount,
            'amount_received' => $amount,
        ]);

        app(BookingPostingService::class)->sync($booking);

        return $booking->fresh();
    }

    public function test_process_creates_contra_journal_entry(): void
    {
        $booking = $this->makePostedBooking(1_000_000);

        $refund = app(RefundService::class)->process($booking, 300_000, 'Customer batal sebagian', null);

        $this->assertEquals(300_000, (float) $refund->amount);
        $this->assertNotNull($refund->journal_entry_id);
        $this->assertTrue($refund->journalEntry->isPosted());
        $this->assertEquals(300_000, (float) $refund->journalEntry->lines()->sum('credit'));
    }

    public function test_refund_can_be_partial_multiple_times_up_to_total(): void
    {
        $booking = $this->makePostedBooking(1_000_000);
        $service = app(RefundService::class);

        $service->process($booking, 400_000, null, null);
        $service->process($booking->fresh(), 600_000, null, null);

        $this->assertEquals(1_000_000, (float) $booking->fresh()->refunds()->sum('amount'));
    }

    public function test_refund_exceeding_remaining_amount_is_rejected(): void
    {
        $booking = $this->makePostedBooking(1_000_000);
        $service = app(RefundService::class);

        $service->process($booking, 700_000, null, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/melebihi sisa/');

        $service->process($booking->fresh(), 400_000, null, null);
    }

    public function test_refund_rejected_when_booking_has_no_journal_entry_yet(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000001']);
        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'PPF',
            'preferred_date' => now()->toDateString(),
            'status' => 'completed',
            'transaction_amount' => 1_000_000,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/belum punya jurnal pendapatan/');

        app(RefundService::class)->process($booking, 100_000, null, null);
    }

    public function test_refund_zero_or_negative_amount_is_rejected(): void
    {
        $booking = $this->makePostedBooking(1_000_000);

        $this->expectException(RuntimeException::class);

        app(RefundService::class)->process($booking, 0, null, null);
    }
}

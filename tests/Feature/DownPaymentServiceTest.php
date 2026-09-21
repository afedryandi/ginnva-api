<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Services\DownPaymentService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Keputusan atasan 2026-09-19 (Topik 2, "Keputusan-PPN-DP-Produk-Stok-
 * Ginnva.docx"): DP fleksibel kapan saja, nominal bebas, dikembalikan
 * PENUH kalau booking dibatalkan. Lihat DownPaymentService.
 */
class DownPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
    }

    private function makeBooking(array $overrides = []): Booking
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000001']);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'pending',
        ], $overrides));
    }

    public function test_receive_creates_deferred_revenue_journal_not_income(): void
    {
        $booking = $this->makeBooking();

        $dp = app(DownPaymentService::class)->receive($booking, 500_000, 'DP awal', null);

        $this->assertEquals(500_000, (float) $dp->amount);
        $this->assertNotNull($dp->journal_entry_id);
        $this->assertTrue($dp->journalEntry->isPosted());

        // Kredit HARUS ke akun 2140 (Pendapatan Diterima Dimuka), BUKAN
        // akun pendapatan 4100/4200 -- DP belum boleh dianggap pendapatan.
        $creditAccountCode = $dp->journalEntry->lines()->where('credit', '>', 0)->first()->account->code;
        $this->assertEquals('2140', $creditAccountCode);
    }

    public function test_receive_can_be_recorded_multiple_times(): void
    {
        $booking = $this->makeBooking();
        $service = app(DownPaymentService::class);

        $service->receive($booking, 300_000, null, null);
        $service->receive($booking->fresh(), 200_000, null, null);

        $this->assertEquals(500_000, (float) $booking->fresh()->outstanding_down_payment);
        $this->assertEquals(2, $booking->fresh()->downPayments()->count());
    }

    public function test_receive_rejected_when_booking_already_completed_or_cancelled(): void
    {
        $booking = $this->makeBooking(['status' => 'completed']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sudah berstatus/');

        app(DownPaymentService::class)->receive($booking, 100_000, null, null);
    }

    public function test_receive_zero_or_negative_amount_is_rejected(): void
    {
        $booking = $this->makeBooking();

        $this->expectException(RuntimeException::class);

        app(DownPaymentService::class)->receive($booking, 0, null, null);
    }

    public function test_refund_reverses_journal_and_marks_refunded(): void
    {
        $booking = $this->makeBooking();
        $dp = app(DownPaymentService::class)->receive($booking, 500_000, null, null);

        $refunded = app(DownPaymentService::class)->refund($dp, null, 'Booking dibatalkan');

        $this->assertTrue($refunded->isRefunded());
        $this->assertNotNull($refunded->refund_journal_entry_id);
        $this->assertTrue($refunded->refundJournalEntry->isPosted());
        $this->assertEquals(0.0, $booking->fresh()->outstanding_down_payment);
    }

    public function test_refund_twice_is_rejected(): void
    {
        $booking = $this->makeBooking();
        $dp = app(DownPaymentService::class)->receive($booking, 500_000, null, null);
        app(DownPaymentService::class)->refund($dp, null, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sudah pernah dikembalikan/');

        app(DownPaymentService::class)->refund($dp->fresh(), null, null);
    }

    public function test_refund_all_on_cancellation_refunds_every_unrefunded_dp(): void
    {
        $booking = $this->makeBooking();
        $service = app(DownPaymentService::class);

        $service->receive($booking, 300_000, null, null);
        $service->receive($booking->fresh(), 200_000, null, null);

        $refunded = $service->refundAllOnCancellation($booking->id, null);

        $this->assertCount(2, $refunded);
        $this->assertEquals(0.0, $booking->fresh()->outstanding_down_payment);
    }

    public function test_refund_all_on_cancellation_is_noop_when_no_down_payments(): void
    {
        $booking = $this->makeBooking();

        $refunded = app(DownPaymentService::class)->refundAllOnCancellation($booking->id, null);

        $this->assertCount(0, $refunded);
    }

    public function test_booking_cancellation_via_filament_quick_cancel_auto_refunds_dp(): void
    {
        // Regression end-to-end: bukan cuma DownPaymentService diuji
        // terisolasi, tapi alur pembatalan booking (status -> 'cancelled')
        // BENAR-BENAR memicu refund otomatis -- lihat 3 titik panggil di
        // BookingResource::quickCancel / Staff & Customer BookingController.
        $booking = $this->makeBooking(['status' => 'confirmed']);
        app(DownPaymentService::class)->receive($booking, 500_000, null, null);

        \Illuminate\Support\Facades\DB::transaction(function () use ($booking) {
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();
            $locked->update(['status' => 'cancelled']);
            app(DownPaymentService::class)->refundAllOnCancellation($locked->id, null);
        });

        $this->assertEquals(0.0, $booking->fresh()->outstanding_down_payment);
        $this->assertTrue($booking->fresh()->downPayments()->first()->isRefunded());
    }
}

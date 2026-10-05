<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\Store;
use App\Services\BookingPostingService;
use App\Services\ReceivableService;
use App\Services\RefundService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Audit Piutang Usaha 2026-09-29: pelunasan memperbarui bookings.amount_received,
 * pembatalan pelunasan/piutang lewat jurnal pembalik, idempotensi per sumber,
 * validasi nominal/tanggal, dan batas refund = uang yang sudah diterima.
 * (Belum pernah dijalankan lokal -- tidak ada PHP; cek hasil CI.)
 */
class ReceivableServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReceivableService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
        $this->service = app(ReceivableService::class);
    }

    private function bookingWithReceivable(float $total = 1_000_000, float $received = 400_000): array
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000001']);

        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'completed',
            'transaction_amount' => $total,
            'amount_received' => $received,
        ]);

        app(BookingPostingService::class)->sync($booking);

        $receivable = Receivable::where('source_type', 'booking')->where('source_id', $booking->id)->firstOrFail();

        return [$booking, $receivable];
    }

    private function manualReceivable(float $amount = 500_000): Receivable
    {
        $revenue = ChartOfAccount::where('type', 'pendapatan_lain')->where('is_postable', true)->firstOrFail();

        return $this->service->createWithJournal([
            'customer_name' => 'Pak Andi',
            'store_id' => null,
            'amount' => $amount,
        ], $revenue->id);
    }

    public function test_payment_increases_booking_amount_received(): void
    {
        [$booking, $receivable] = $this->bookingWithReceivable();

        $this->service->recordPayment($receivable, 100_000, now(), null);

        $this->assertEquals(500_000.0, (float) $booking->fresh()->amount_received);
    }

    public function test_full_payment_makes_booking_fully_received(): void
    {
        [$booking, $receivable] = $this->bookingWithReceivable();

        $this->service->recordPayment($receivable, 600_000, now(), null);

        $this->assertEquals(1_000_000.0, (float) $booking->fresh()->amount_received);
        $this->assertSame('paid', $receivable->fresh()->status);
    }

    public function test_void_payment_restores_receivable_and_booking(): void
    {
        [$booking, $receivable] = $this->bookingWithReceivable();
        $payment = $this->service->recordPayment($receivable, 600_000, now(), null);

        $this->service->voidPayment($payment, null, 'Salah input');

        $this->assertSame('unpaid', $receivable->fresh()->status);
        $this->assertEquals(400_000.0, (float) $booking->fresh()->amount_received);
        $this->assertNotNull($payment->fresh()->void_journal_entry_id);
    }

    public function test_cannot_void_twice(): void
    {
        [, $receivable] = $this->bookingWithReceivable();
        $payment = $this->service->recordPayment($receivable, 100_000, now(), null);
        $this->service->voidPayment($payment, null, 'Salah');

        $this->expectException(RuntimeException::class);

        $this->service->voidPayment($payment->fresh(), null, 'Lagi');
    }

    public function test_overpayment_is_rejected(): void
    {
        [, $receivable] = $this->bookingWithReceivable();

        $this->expectException(RuntimeException::class);

        $this->service->recordPayment($receivable, 600_000.01, now(), null);
    }

    public function test_future_payment_date_is_rejected(): void
    {
        [, $receivable] = $this->bookingWithReceivable();

        $this->expectException(RuntimeException::class);

        $this->service->recordPayment($receivable, 100_000, now()->addDay(), null);
    }

    public function test_non_positive_amount_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->manualReceivable(0);
    }

    public function test_duplicate_booking_source_is_rejected(): void
    {
        [$booking] = $this->bookingWithReceivable();

        $this->expectException(RuntimeException::class);

        $this->service->create([
            'customer_name' => 'Budi',
            'store_id' => $booking->store_id,
            'amount' => 100_000,
            'source_type' => 'booking',
            'source_id' => $booking->id,
        ]);
    }

    public function test_cancel_manual_receivable_blocks_payment(): void
    {
        $receivable = $this->manualReceivable();

        $cancelled = $this->service->cancelReceivable($receivable, null, 'Salah input');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancel_journal_entry_id);

        $this->expectException(RuntimeException::class);
        $this->service->recordPayment($cancelled->fresh(), 100_000, now(), null);
    }

    public function test_booking_receivable_cannot_be_cancelled_here(): void
    {
        [, $receivable] = $this->bookingWithReceivable();

        $this->expectException(RuntimeException::class);

        $this->service->cancelReceivable($receivable, null, 'Coba');
    }

    public function test_creator_of_manual_receivable_cannot_receive_own_payment(): void
    {
        $creator = \App\Models\User::create(['name' => 'Kreator', 'email' => 'kreator@test.local', 'password' => 'x']);
        $revenue = ChartOfAccount::where('type', 'pendapatan_lain')->where('is_postable', true)->firstOrFail();

        $receivable = $this->service->createWithJournal([
            'customer_name' => 'Pak Andi',
            'store_id' => null,
            'amount' => 500_000,
            'created_by' => $creator->id,
        ], $revenue->id);

        $this->expectException(RuntimeException::class);

        $this->service->recordPayment($receivable, 100_000, now(), $creator->id);
    }

    public function test_payment_gets_receipt_number(): void
    {
        [, $receivable] = $this->bookingWithReceivable();

        $payment = $this->service->recordPayment($receivable, 100_000, now(), null);

        $this->assertStringStartsWith('RC-', $payment->receipt_number);
    }

    public function test_booking_receivable_gets_due_date_14_days(): void
    {
        [, $receivable] = $this->bookingWithReceivable();

        $this->assertNotNull($receivable->due_date);
        $this->assertTrue($receivable->due_date->isSameDay(now()->addDays(14)));
    }

    public function test_reconcile_matches_for_booking_receivable(): void
    {
        $this->bookingWithReceivable(1_000_000, 400_000);

        $r = $this->service->reconcile();

        $this->assertEquals(600_000.0, $r['gl']);
        $this->assertEquals(0.0, $r['diff']);
    }

    public function test_refund_reduces_receivable_first_without_cash_out(): void
    {
        [$booking, $receivable] = $this->bookingWithReceivable(1_000_000, 400_000);

        $refund = app(RefundService::class)->process($booking->fresh(), 500_000, 'Uji', null);

        $this->assertEquals(500_000.0, (float) $refund->receivable_reduced);
        $this->assertEquals(100_000.0, (float) $receivable->fresh()->amount);
        $this->assertEquals(900_000.0, (float) $booking->fresh()->amount_received);
        // Tidak ada kredit Kas: hanya kredit 1110 sebesar 500.000.
        $cashCredit = $refund->journalEntry->lines()->whereHas('account', fn ($q) => $q->where('code', '1101'))->sum('credit');
        $this->assertEquals(0.0, (float) $cashCredit);
    }

    public function test_refund_of_whole_booking_cancels_receivable_and_returns_only_received_cash(): void
    {
        [$booking, $receivable] = $this->bookingWithReceivable(1_000_000, 400_000);

        $refund = app(RefundService::class)->process($booking->fresh(), 1_000_000, 'Batal total', null);

        $this->assertEquals(600_000.0, (float) $refund->receivable_reduced);
        $this->assertSame('cancelled', $receivable->fresh()->status);
        $cashCredit = $refund->journalEntry->lines()->whereHas('account', fn ($q) => $q->where('code', '1101'))->sum('credit');
        $this->assertEquals(400_000.0, (float) $cashCredit);
    }

    public function test_refund_cannot_exceed_booking_total(): void
    {
        [$booking] = $this->bookingWithReceivable(1_000_000, 400_000);

        $this->expectException(RuntimeException::class);

        app(RefundService::class)->process($booking->fresh(), 1_000_000.01, 'Uji', null);
    }
}

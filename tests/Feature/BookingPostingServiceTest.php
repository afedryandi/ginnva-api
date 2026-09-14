<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Services\BookingPostingService;
use App\Services\ReceivableService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Audit framework 2026-09-14, "Cakupan automated testing" -- modul
 * finansial kritikal (posting jurnal booking) belum punya test
 * otomatis sama sekali sebelum ini.
 */
class BookingPostingServiceTest extends TestCase
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
            'status' => 'completed',
        ], $overrides));
    }

    public function test_sync_creates_posted_journal_entry_for_full_cash_payment(): void
    {
        $booking = $this->makeBooking(['transaction_amount' => 1_000_000, 'amount_received' => 1_000_000]);

        $entry = app(BookingPostingService::class)->sync($booking);

        $this->assertNotNull($entry);
        $this->assertTrue($entry->isPosted());
        $this->assertEquals(1_000_000, (float) $entry->lines()->sum('debit'));
        $this->assertEquals(1_000_000, (float) $entry->lines()->sum('credit'));
        $this->assertEquals($entry->id, $booking->fresh()->journal_entry_id);
    }

    public function test_sync_splits_outstanding_amount_to_receivable(): void
    {
        $booking = $this->makeBooking(['transaction_amount' => 1_000_000, 'amount_received' => 400_000]);

        app(BookingPostingService::class)->sync($booking);

        $receivable = \App\Models\Receivable::where('source_type', 'booking')->where('source_id', $booking->id)->first();

        $this->assertNotNull($receivable, 'Piutang Usaha harus tercatat untuk selisih yang belum lunas.');
        $this->assertEquals(600_000, (float) $receivable->amount);
    }

    public function test_sync_splits_revenue_5050_when_both_ppf_and_kaca_film(): void
    {
        $booking = $this->makeBooking([
            'transaction_amount' => 1_000_001, // ganjil, cek pembulatan tidak buang recehan
            'amount_received' => 1_000_001,
            'product_ppf' => true,
            'product_kaca_film' => true,
        ]);

        $entry = app(BookingPostingService::class)->sync($booking);

        $credits = $entry->lines()->where('credit', '>', 0)->pluck('credit');
        $this->assertCount(2, $credits, 'Harus ada 2 baris kredit pendapatan (PPF & Kaca Film).');
        $this->assertEquals(1_000_001, (float) $credits->sum(), 'Total split harus PERSIS sama dengan nominal, walau ganjil.');
    }

    public function test_resync_reverses_previous_journal_before_posting_new_one(): void
    {
        $booking = $this->makeBooking(['transaction_amount' => 1_000_000, 'amount_received' => 1_000_000]);
        $service = app(BookingPostingService::class);

        $firstEntry = $service->sync($booking);
        $booking->refresh()->update(['transaction_amount' => 2_000_000, 'amount_received' => 2_000_000]);
        $secondEntry = $service->sync($booking->fresh());

        $this->assertTrue($firstEntry->fresh()->reversal()->exists(), 'Jurnal lama harus dibalik, bukan diedit/dihapus.');
        $this->assertNotEquals($firstEntry->id, $secondEntry->id);
        $this->assertEquals(2_000_000, (float) $secondEntry->lines()->sum('debit'));
    }

    public function test_sync_clears_journal_when_amount_zeroed_out(): void
    {
        $booking = $this->makeBooking(['transaction_amount' => 1_000_000, 'amount_received' => 1_000_000]);
        $service = app(BookingPostingService::class);

        $service->sync($booking);
        $booking->refresh()->update(['transaction_amount' => 0]);
        $result = $service->sync($booking->fresh());

        $this->assertNull($result);
        $this->assertNull($booking->fresh()->journal_entry_id);
    }

    public function test_sync_refuses_to_replace_receivable_that_already_has_payment(): void
    {
        $booking = $this->makeBooking(['transaction_amount' => 1_000_000, 'amount_received' => 400_000]);
        app(BookingPostingService::class)->sync($booking);

        $receivable = \App\Models\Receivable::where('source_type', 'booking')->where('source_id', $booking->id)->first();
        app(ReceivableService::class)->recordPayment($receivable, 100_000, now(), null);

        $booking->update(['transaction_amount' => 3_000_000]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sudah ADA pelunasan/');

        app(BookingPostingService::class)->sync($booking->fresh());
    }
}

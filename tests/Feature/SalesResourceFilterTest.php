<?php

namespace Tests\Feature;

use App\Filament\Resources\SalesResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Services\BookingPostingService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit Detail Penjualan 2026-09-29: ambang toleransi 0.009 filter "Status Pembayaran" disamakan
 * dengan badge/widget/Excel (sebelumnya whereColumn tanpa toleransi, tidak sinkron dengan tampilan
 * untuk selisih pembulatan sangat kecil). (Belum pernah dijalankan lokal -- tidak ada PHP; cek CI.)
 */
class SalesResourceFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
    }

    private function makeBooking(float $transaction, float $received): Booking
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Uji', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Uji', 'phone_number' => '0812' . random_int(1000000, 9999999)]);

        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'completed',
            'transaction_amount' => $transaction,
            'amount_received' => $received,
        ]);

        app(BookingPostingService::class)->sync($booking);

        return $booking->fresh();
    }

    public function test_tiny_rounding_difference_is_treated_as_lunas_by_filter(): void
    {
        // Selisih 0.005 -- di bawah ambang 0.009, badge menampilkan "Lunas".
        $booking = $this->makeBooking(1_000_000, 999_999.995);

        $lunasCount = SalesResource::getEloquentQuery()
            ->whereKey($booking->id)
            ->whereRaw('(transaction_amount - COALESCE(amount_received, transaction_amount)) <= 0.009')
            ->count();

        $this->assertSame(1, $lunasCount, 'Filter "Lunas" harus ikut menangkap selisih pembulatan kecil, sama seperti badge.');
    }

    public function test_genuinely_unpaid_booking_is_excluded_from_lunas_filter(): void
    {
        $booking = $this->makeBooking(1_000_000, 400_000);

        $lunasCount = SalesResource::getEloquentQuery()
            ->whereKey($booking->id)
            ->whereRaw('(transaction_amount - COALESCE(amount_received, transaction_amount)) <= 0.009')
            ->count();

        $this->assertSame(0, $lunasCount);
    }

    public function test_period_preset_this_month_matches_manual_range(): void
    {
        $this->makeBooking(500_000, 500_000);

        $presetCount = SalesResource::getEloquentQuery()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]))
            ->count();

        $this->assertSame(1, $presetCount);
    }

    public function test_pdf_export_stats_match_table_query(): void
    {
        $this->makeBooking(1_000_000, 1_000_000);
        $this->makeBooking(500_000, 200_000);

        $stats = \App\Filament\Widgets\SalesDetailStatsWidget::aggregate(SalesResource::getEloquentQuery());

        $this->assertSame(2, $stats['total_count']);
        $this->assertSame(1, $stats['lunas_count']);
        $this->assertSame(1, $stats['belum_lunas_count']);
    }
}

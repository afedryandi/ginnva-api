<?php

namespace Tests\Feature;

use App\Filament\Pages\SalesSummaryReport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Services\BookingPostingService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit Ringkasan Penjualan 2026-09-29: tanggal terbalik dikoreksi (bukan diam-diam Rp 0), dan
 * kolom pembanding periode. (Belum pernah dijalankan lokal -- tidak ada PHP; cek hasil CI.)
 */
class SalesSummaryReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
    }

    private function makeBooking(string $preferredDate, float $amount): Booking
    {
        $store = Store::firstOrCreate(['name' => 'Toko Uji'], ['is_active' => true]);
        $customer = Customer::create(['name' => 'Uji', 'phone_number' => '08120000' . random_int(1000, 9999)]);

        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => $preferredDate,
            'status' => 'completed',
            'transaction_amount' => $amount,
            'amount_received' => $amount,
        ]);

        app(BookingPostingService::class)->sync($booking);

        return $booking;
    }

    public function test_mount_corrects_reversed_date_range(): void
    {
        request()->merge(['from' => '2026-09-30', 'to' => '2026-09-01']);

        $page = new SalesSummaryReport();
        $page->from = '2026-09-30';
        $page->to = '2026-09-01';
        $page->mount();

        $this->assertSame($page->from, $page->to);
    }

    public function test_comparison_range_prev_period_matches_month_length(): void
    {
        $page = new SalesSummaryReport();
        $page->data = ['compare' => 'prev_period'];

        $ref = new \ReflectionMethod(SalesSummaryReport::class, 'comparisonRange');
        $ref->setAccessible(true);

        [$prevFrom, $prevTo] = $ref->invoke($page, \Illuminate\Support\Carbon::parse('2026-09-01'), \Illuminate\Support\Carbon::parse('2026-09-30')->endOfDay());

        $this->assertTrue($prevFrom->isSameDay('2026-08-01'));
        $this->assertTrue($prevTo->isSameDay('2026-08-31'));
    }

    public function test_get_result_includes_comparison_snapshot(): void
    {
        $this->makeBooking(now()->subMonthNoOverflow()->startOfMonth()->addDays(2)->toDateString(), 500_000);
        $this->makeBooking(now()->startOfMonth()->addDays(2)->toDateString(), 1_000_000);

        $page = new SalesSummaryReport();
        $page->data = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'compare' => 'prev_period',
        ];

        $result = $page->getResult();

        $this->assertNotNull($result['compare']);
        $this->assertEquals(500_000.0, $result['compare']['net']);
        $this->assertEquals(1_000_000.0, $result['netSales']);
    }
}

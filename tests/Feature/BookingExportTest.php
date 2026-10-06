<?php

namespace Tests\Feature;

use App\Exports\BookingExport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Services\DownPaymentService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kolom Ekspor Booking: tahap, installer, durasi, tanggal selesai, DP.
 */
class BookingExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_rows_match_headings_and_include_stage_duration_and_dp(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id'    => $customer->id,
            'store_id'       => $store->id,
            'service_type'   => 'Pelindung Cat (PPF)',
            'product_ppf'    => true,
            'preferred_date' => now()->addDays(5)->toDateString(),
            'status'         => 'confirmed',
            'current_stage'  => 'ppf_detailing',
            'duration_days'  => 2,
        ]);
        app(DownPaymentService::class)->receive($booking, 300_000, null, null);

        $export = new BookingExport();
        $loaded = $export->query()->get()->firstWhere('id', $booking->id);
        $row = $export->map($loaded);

        $this->assertCount(count($export->headings()), $row);

        $byHeading = array_combine($export->headings(), $row);
        $this->assertSame('Detailing', $byHeading['Tahap']);
        $this->assertSame(2, $byHeading['Durasi (hari)']);
        $this->assertEquals(300_000, $byHeading['DP Diterima']);
        $this->assertEquals(300_000, $byHeading['Sisa DP']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Technician;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit Majoo f34 ("Tarif berbeda per teknisi utk layanan yang sama"),
 * dibangun 2026-09-22 atas keputusan user. Fokus test: fallback flat
 * untuk teknisi tanpa serviceRates (backward compatible), penjumlahan
 * tarif untuk booking kombo (keputusan user), dan booking dianggap
 * "belum diatur" (null) kalau ada jenis layanan yang tarifnya belum
 * diisi -- tidak pernah dihitung parsial/Rp 0 diam-diam.
 */
class TechnicianCommissionForBookingTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(array $productFlags): Booking
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000001']);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'Test',
            'preferred_date' => now()->toDateString(),
            'status' => 'completed',
        ], $productFlags));
    }

    public function test_flat_commission_used_when_no_service_rates_configured(): void
    {
        $technician = Technician::create(['name' => 'Teknisi A', 'commission_amount' => 100_000]);
        $booking = $this->makeBooking(['product_ppf' => true]);

        $this->assertEquals(100_000.0, $technician->commissionForBooking($booking));
    }

    public function test_flat_commission_null_when_not_configured_and_no_service_rates(): void
    {
        $technician = Technician::create(['name' => 'Teknisi B', 'commission_amount' => null]);
        $booking = $this->makeBooking(['product_ppf' => true]);

        $this->assertNull($technician->commissionForBooking($booking));
    }

    public function test_service_rates_ignore_flat_commission_once_configured(): void
    {
        $technician = Technician::create(['name' => 'Teknisi C', 'commission_amount' => 999_999]);
        $technician->serviceRates()->create(['service_type' => 'ppf', 'commission_amount' => 150_000]);

        $booking = $this->makeBooking(['product_ppf' => true]);

        $this->assertEquals(150_000.0, $technician->commissionForBooking($booking));
    }

    public function test_combo_booking_sums_rates_of_each_matched_service(): void
    {
        $technician = Technician::create(['name' => 'Teknisi D']);
        $technician->serviceRates()->create(['service_type' => 'ppf', 'commission_amount' => 150_000]);
        $technician->serviceRates()->create(['service_type' => 'detailing', 'commission_amount' => 50_000]);

        $booking = $this->makeBooking(['product_ppf' => true, 'product_detailing' => true]);

        $this->assertEquals(200_000.0, $technician->commissionForBooking($booking));
    }

    public function test_missing_rate_for_matched_service_makes_whole_booking_unrated(): void
    {
        $technician = Technician::create(['name' => 'Teknisi E']);
        $technician->serviceRates()->create(['service_type' => 'ppf', 'commission_amount' => 150_000]);
        // Tidak ada tarif utk 'kaca_film'.

        $booking = $this->makeBooking(['product_ppf' => true, 'product_kaca_film' => true]);

        $this->assertNull($technician->commissionForBooking($booking));
    }

    public function test_booking_with_no_matched_product_flags_is_unrated_in_service_rate_mode(): void
    {
        $technician = Technician::create(['name' => 'Teknisi F']);
        $technician->serviceRates()->create(['service_type' => 'ppf', 'commission_amount' => 150_000]);

        $booking = $this->makeBooking([]); // tidak ada flag produk sama sekali

        $this->assertNull($technician->commissionForBooking($booking));
    }
}

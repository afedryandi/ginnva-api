<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Keputusan atasan 2026-09-19 (Topik 1, "Keputusan-PPN-DP-Produk-Stok-
 * Ginnva.docx"): harga customer SUDAH inclusive PPN 11%, berlaku booking
 * baru saja (tidak retroaktif). Lihat Booking::applyPpnBreakdown().
 */
class BookingPpnBreakdownTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_dpp_and_ppn_computed_automatically_from_inclusive_total(): void
    {
        // Total bulat Rp1.110.000 -> DPP Rp1.000.000, PPN Rp110.000 persis
        // (dipilih sengaja supaya hasil baginya genap, memudahkan assert).
        $booking = $this->makeBooking(['transaction_amount' => 1_110_000]);

        $this->assertEquals(1_000_000.00, (float) $booking->dpp_amount);
        $this->assertEquals(110_000.00, (float) $booking->ppn_amount);
    }

    public function test_dpp_plus_ppn_always_reconciles_back_to_total_even_with_rounding(): void
    {
        // Angka ganjil supaya pembagian 1.11 menghasilkan pecahan -- DPP +
        // PPN WAJIB tetap persis sama dengan total (PPN = Total - DPP,
        // bukan dihitung terpisah dari rate, supaya tidak pernah selisih
        // recehan karena pembulatan ganda).
        $booking = $this->makeBooking(['transaction_amount' => 1_000_001]);

        $this->assertEquals(
            (float) $booking->transaction_amount,
            round((float) $booking->dpp_amount + (float) $booking->ppn_amount, 2)
        );
    }

    public function test_dpp_and_ppn_null_when_transaction_amount_empty(): void
    {
        $booking = $this->makeBooking(['transaction_amount' => null]);

        $this->assertNull($booking->dpp_amount);
        $this->assertNull($booking->ppn_amount);
    }

    public function test_dpp_and_ppn_recomputed_when_transaction_amount_edited(): void
    {
        $booking = $this->makeBooking(['transaction_amount' => 1_110_000]);
        $this->assertEquals(110_000.00, (float) $booking->ppn_amount);

        $booking->update(['transaction_amount' => 2_220_000]);

        $this->assertEquals(2_000_000.00, (float) $booking->fresh()->dpp_amount);
        $this->assertEquals(220_000.00, (float) $booking->fresh()->ppn_amount);
    }

    public function test_dpp_and_ppn_untouched_when_other_fields_change(): void
    {
        // 'saving' cuma boleh menghitung ulang kalau transaction_amount
        // DIRTY -- update kolom lain (mis. notes) TIDAK BOLEH mengubah
        // dpp_amount/ppn_amount yang sudah tersimpan.
        $booking = $this->makeBooking(['transaction_amount' => 1_110_000]);
        $originalDpp = $booking->dpp_amount;
        $originalPpn = $booking->ppn_amount;

        $booking->update(['notes' => 'Catatan baru, tidak menyentuh nominal']);

        $this->assertEquals($originalDpp, $booking->fresh()->dpp_amount);
        $this->assertEquals($originalPpn, $booking->fresh()->ppn_amount);
    }

    public function test_setting_transaction_amount_to_zero_clears_ppn_breakdown(): void
    {
        $booking = $this->makeBooking(['transaction_amount' => 1_110_000]);

        $booking->update(['transaction_amount' => 0]);

        $this->assertNull($booking->fresh()->dpp_amount);
        $this->assertNull($booking->fresh()->ppn_amount);
    }
}

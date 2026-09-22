<?php

namespace Tests\Feature;

use App\Models\Booking;
use Tests\TestCase;

/**
 * Audit Majoo f31 ("Kasir bisa nego harga terbatas dgn cap %"),
 * dibangun 2026-09-22 atas keputusan user: staff booking non-full-
 * access boleh proses transaksi sendiri (tanpa approval) kalau
 * diskonnya ≤5% dari harga acuan matriks. Referensi yang tidak
 * diketahui SELALU wajib approval -- tidak pernah diloloskan diam-diam.
 */
class BookingNegotiationCapTest extends TestCase
{
    public function test_full_price_or_upsell_always_within_cap(): void
    {
        $this->assertTrue(Booking::isWithinNegotiationCap(1_000_000, 1_000_000));
        $this->assertTrue(Booking::isWithinNegotiationCap(1_000_000, 1_200_000));
    }

    public function test_discount_within_five_percent_is_within_cap(): void
    {
        // 5% dari 1jt = 50rb -> 950rb pas di batas.
        $this->assertTrue(Booking::isWithinNegotiationCap(1_000_000, 950_000));
        $this->assertTrue(Booking::isWithinNegotiationCap(1_000_000, 960_000));
    }

    public function test_discount_over_five_percent_exceeds_cap(): void
    {
        $this->assertFalse(Booking::isWithinNegotiationCap(1_000_000, 949_000));
        $this->assertFalse(Booking::isWithinNegotiationCap(1_000_000, 500_000));
    }

    public function test_unknown_reference_price_never_bypasses_approval(): void
    {
        $this->assertFalse(Booking::isWithinNegotiationCap(null, 500_000));
        $this->assertFalse(Booking::isWithinNegotiationCap(0, 500_000));
        $this->assertFalse(Booking::isWithinNegotiationCap(-1, 500_000));
    }
}

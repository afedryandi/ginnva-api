<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Partner;
use App\Models\PartnerPointTransaction;
use App\Models\PointTransaction;
use Illuminate\Support\Facades\DB;

class ReferralPointService
{
    /**
     * Rasio konversi poin — 1 poin per Rp 10.000 transaksi, sama untuk
     * partner maupun customer. Default sementara (belum ada aturan bisnis
     * resmi) — ubah angka ini kalau rasionya berubah, dipakai di satu
     * tempat saja supaya gampang di-maintain.
     */
    private const RUPIAH_PER_POINT = 10000;

    /**
     * Dipanggil saat staff toko menandai booking selesai + input kode
     * referral & nominal transaksi. Kasih poin ke partner (pemilik kode)
     * DAN ke customer booking tersebut, dalam 1 DB transaction supaya
     * tidak ada poin yang "nyangkut" kalau salah satu gagal.
     *
     * Aman dipanggil berkali-kali untuk booking yang sama — hanya
     * memproses sekali (idempotent, cek reference_type+reference_id).
     */
    public function awardForBooking(Booking $booking): ?Partner
    {
        if (empty($booking->referral_code) || empty($booking->transaction_amount)) {
            return null;
        }

        $partner = Partner::where('referral_code', $booking->referral_code)
            ->where('status', 'active')
            ->first();

        if (! $partner) {
            return null;
        }

        // Idempotency guard — cek apakah booking ini sudah pernah diproses.
        $alreadyProcessed = PartnerPointTransaction::where('reference_type', 'booking')
            ->where('reference_id', $booking->id)
            ->exists();

        if ($alreadyProcessed) {
            return $partner;
        }

        $points = (int) floor($booking->transaction_amount / self::RUPIAH_PER_POINT);

        if ($points < 1) {
            return $partner;
        }

        DB::transaction(function () use ($booking, $partner, $points) {
            // Poin untuk partner
            PartnerPointTransaction::create([
                'partner_id'     => $partner->id,
                'type'           => 'earn',
                'points'         => $points,
                'description'    => "Referral booking #{$booking->booking_number}",
                'reference_type' => 'booking',
                'reference_id'   => $booking->id,
            ]);
            $partner->increment('points_balance', $points);
            $booking->partner_id = $partner->id;
            $booking->saveQuietly();

            // Poin untuk customer (kalau booking ini terikat akun customer —
            // booking manual/walk-in dari staff tidak selalu punya customer_id)
            if ($booking->customer_id) {
                PointTransaction::create([
                    'customer_id'    => $booking->customer_id,
                    'type'           => 'earn',
                    'points'         => $points,
                    'description'    => "Booking #{$booking->booking_number} (kode referral {$partner->referral_code})",
                    'reference_type' => 'booking',
                    'reference_id'   => $booking->id,
                ]);
                $booking->customer()->increment('loyalty_points', $points);
            }
        });

        return $partner;
    }
}

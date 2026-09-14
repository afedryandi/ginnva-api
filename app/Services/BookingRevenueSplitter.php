<?php

namespace App\Services;

use App\Models\Booking;

/**
 * Satu-satunya implementasi split pendapatan booking ke akun Jurnal
 * Umum per produk (PPF/Kaca Film) — diekstrak 2026-09-14 (audit
 * framework, "Duplikasi logika bisnis") dari BookingPostingService &
 * RefundService yang SEBELUMNYA punya method + konstanta kode akun
 * byte-for-byte identik (RefundService bahkan sudah menulis komentar
 * sendiri "SAMA PERSIS logika revenueSplits() di BookingPostingService" --
 * sinyal jelas seharusnya sudah diekstrak dari awal).
 */
class BookingRevenueSplitter
{
    public const PPF_REVENUE_ACCOUNT_CODE = '4100';
    public const KACA_FILM_REVENUE_ACCOUNT_CODE = '4200';
    public const FALLBACK_REVENUE_ACCOUNT_CODE = '4400';

    /**
     * @return array<string, float> kode akun => nominal. Split RATA
     *         50/50 kalau booking punya PPF & Kaca Film sekaligus (sisa,
     *         bukan $half lagi, dipakai untuk baris kedua supaya total 2
     *         baris SELALU persis sama dengan $amount walau $amount ganjil).
     */
    public static function splits(Booking $booking, float $amount): array
    {
        if ($booking->product_ppf && $booking->product_kaca_film) {
            $half = round($amount / 2, 2);

            return [
                self::PPF_REVENUE_ACCOUNT_CODE => $half,
                self::KACA_FILM_REVENUE_ACCOUNT_CODE => round($amount - $half, 2),
            ];
        }

        if ($booking->product_ppf) {
            return [self::PPF_REVENUE_ACCOUNT_CODE => $amount];
        }

        if ($booking->product_kaca_film) {
            return [self::KACA_FILM_REVENUE_ACCOUNT_CODE => $amount];
        }

        return [self::FALLBACK_REVENUE_ACCOUNT_CODE => $amount];
    }
}

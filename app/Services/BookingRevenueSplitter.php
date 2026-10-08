<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\JournalEntryLine;

/**
 * Satu-satunya implementasi split pendapatan booking ke akun Jurnal
 * Umum per jenis layanan — diekstrak 2026-09-14 (audit framework,
 * "Duplikasi logika bisnis") dari BookingPostingService & RefundService
 * yang SEBELUMNYA punya method + konstanta kode akun byte-for-byte
 * identik.
 *
 * 2026-10-08 (keputusan user): Detailing dan Premium Wash punya akun
 * pendapatan SENDIRI (4500 / 4600), tidak lagi menumpang ke PPF atau
 * "Pendapatan Lain-lain" (4400). Booking dengan lebih dari satu jenis dibagi
 * RATA; pembulatan sen dibebankan ke jenis TERAKHIR (urutan PRODUCTS) supaya
 * total bagian selalu persis sama dengan nominal -- identik dengan aturan lama
 * untuk PPF + Kaca Film (PPF menerima setengah yang dibulatkan, Kaca Film sisanya).
 * Laporan penjualan per jenis (Laporan Jasa / Jenis Order, grafik kategori)
 * memakai shares() yang sama, jadi angkanya tidak menyimpang dari Jurnal Umum.
 */
class BookingRevenueSplitter
{
    public const PPF_REVENUE_ACCOUNT_CODE = '4100';
    public const KACA_FILM_REVENUE_ACCOUNT_CODE = '4200';
    public const DETAILING_REVENUE_ACCOUNT_CODE = '4500';
    public const PREMIUM_WASH_REVENUE_ACCOUNT_CODE = '4600';
    public const FALLBACK_REVENUE_ACCOUNT_CODE = '4400';

    /** Kunci "tanpa jenis" di shares() -- dibukukan ke akun FALLBACK. */
    public const NO_PRODUCT = 'lainnya';

    /**
     * Jenis layanan: kunci => [kolom flag di bookings, kode akun pendapatan]. URUTAN penting: jenis terakhir
     * yang menyala menerima sisa pembulatan.
     */
    public const PRODUCTS = [
        'ppf' => ['product_ppf', self::PPF_REVENUE_ACCOUNT_CODE],
        'kaca_film' => ['product_kaca_film', self::KACA_FILM_REVENUE_ACCOUNT_CODE],
        'detailing' => ['product_detailing', self::DETAILING_REVENUE_ACCOUNT_CODE],
        'premium_wash' => ['product_premium_wash', self::PREMIUM_WASH_REVENUE_ACCOUNT_CODE],
    ];

    /** @return list<string> kunci jenis layanan yang menyala pada booking ini, urut PRODUCTS. */
    public static function productKeys(Booking $booking): array
    {
        $keys = [];

        foreach (self::PRODUCTS as $key => [$flag]) {
            if ($booking->{$flag}) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Bagian pendapatan per jenis layanan (kunci => nominal). Tanpa satu pun jenis: [NO_PRODUCT => $amount].
     *
     * @return array<string, float>
     */
    public static function shares(Booking $booking, float $amount): array
    {
        $keys = self::productKeys($booking);

        if ($keys === []) {
            return [self::NO_PRODUCT => $amount];
        }

        $count = count($keys);
        $shares = [];
        $allocated = 0.0;

        foreach ($keys as $index => $key) {
            if ($index === $count - 1) {
                $shares[$key] = round($amount - $allocated, 2);

                continue;
            }

            $portion = round($amount / $count, 2);
            $shares[$key] = $portion;
            $allocated += $portion;
        }

        return $shares;
    }

    /**
     * Bagian pendapatan per AKUN untuk jurnal pendapatan booking baru.
     *
     * @return array<string, float> kode akun => nominal
     */
    public static function splits(Booking $booking, float $amount): array
    {
        $splits = [];

        foreach (self::shares($booking, $amount) as $key => $portion) {
            $code = $key === self::NO_PRODUCT ? self::FALLBACK_REVENUE_ACCOUNT_CODE : self::PRODUCTS[$key][1];
            $splits[$code] = $portion;
        }

        return $splits;
    }

    /**
     * Bagian per akun untuk REFUND: proporsional dengan jurnal pendapatan booking yang SUDAH terposting, bukan
     * dihitung ulang dari flag produk saat ini. Booking lama (dijurnal sebelum Detailing/Premium Wash punya akun
     * sendiri) harus dibalik ke akun yang dulu benar-benar dikredit -- kalau tidak, refund mendebit akun yang tidak
     * pernah menerima pendapatan itu. Tanpa jurnal pendapatan yang terbaca, kembali ke aturan splits().
     *
     * @return array<string, float> kode akun => nominal (jumlahnya persis $amount)
     */
    public static function refundSplits(Booking $booking, float $amount): array
    {
        if (! $booking->journal_entry_id) {
            return self::splits($booking, $amount);
        }

        $weights = JournalEntryLine::query()
            ->join('chart_of_accounts as accounts', 'accounts.id', '=', 'journal_entry_lines.chart_of_account_id')
            ->where('journal_entry_lines.journal_entry_id', $booking->journal_entry_id)
            ->where('journal_entry_lines.credit', '>', 0)
            ->where('accounts.code', 'like', '4%')
            ->where('accounts.code', '!=', '4900')   // retur & potongan: kontra-pendapatan, bukan bagian pendapatan jasa
            ->groupBy('accounts.code')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.code as code, SUM(journal_entry_lines.credit) as weight')
            ->pluck('weight', 'code')
            ->map(fn ($w) => (float) $w)
            ->all();

        $total = array_sum($weights);

        if ($weights === [] || $total <= 0) {
            return self::splits($booking, $amount);
        }

        $codes = array_keys($weights);
        $last = count($codes) - 1;
        $splits = [];
        $allocated = 0.0;

        foreach ($codes as $index => $code) {
            if ($index === $last) {
                $splits[$code] = round($amount - $allocated, 2);

                continue;
            }

            $portion = round($amount * $weights[$code] / $total, 2);
            $splits[$code] = $portion;
            $allocated += $portion;
        }

        return $splits;
    }
}

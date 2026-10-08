<?php

namespace App\Exports;

use App\Models\Booking;
use App\Models\VoucherClaim;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Promo" / "Laporan Kupon" (PromoLoyaltyReport /
 * CouponReport — logic sama, cuma nama menu beda) — audit 2026-09-11,
 * temuan B. $title dipakai supaya file export dari "Laporan Kupon"
 * tidak keliru bertuliskan "Laporan Promo".
 *
 * Nominal ditulis sebagai ANGKA (bukan teks berformat) supaya bisa dijumlah/difilter di Excel; potongan promo
 * bernilai negatif dan tampil "(1.234)" lewat format sel (lihat styles()). Daftar sel berformat dicatat saat
 * array() dibangun, jadi nomor baris tidak perlu ditulis tetap.
 */
class PromoLoyaltyReportExport implements FromArray, WithStyles
{
    private const MONEY = '#,##0;(#,##0);"-"';

    /** @var list<string> */
    private array $moneyRanges = [];

    public function __construct(private array $result, private string $title = 'Laporan Promo') {}

    public function array(): array
    {
        $r = $this->result;
        $this->moneyRanges = [];
        $number = fn ($n) => round((float) $n, 2);
        $negative = fn ($n) => (float) $n > 0 ? -round((float) $n, 2) : 0.0;   // hindari -0.0

        $rows = [
            [$this->title],
            ['Periode', $r['from']->format('d M Y') . ' - ' . $r['to']->format('d M Y')],
            [],
            ['RINGKASAN TRANSAKSI PROMO'],
            ['Total Transaksi dengan Promo', $r['promoTransactionCount']],
            ['Nilai Promo', $negative($r['promoValue'])],
            ['Total Penjualan dengan Promo', $number($r['promoSalesTotal'])],
            [],
            ['POIN LOYALTI (company-wide, tidak per cabang)'],
            ['Poin Customer Diterbitkan', $r['points']['issued_customer']],
            ['Poin Customer Dipakai', $r['points']['spent_customer']],
            ['Poin Partner Diterbitkan', $r['points']['issued_partner']],
            ['Poin Partner Dipakai', $r['points']['spent_partner']],
            ['Total Klaim Reward', $r['totalRedemptions']],
            [],
            ['DETAIL TRANSAKSI PROMO'],
            ['Tanggal', 'Promo', 'No. Booking', 'Toko', 'Nilai'],
        ];
        $this->moneyRanges[] = 'B6:B7';

        $first = count($rows) + 1;
        foreach ($r['usedClaims'] as $claim) {
            /** @var VoucherClaim $claim */
            $rows[] = [
                optional($claim->used_at)->format('Y-m-d'),
                $claim->voucher?->name ?? '-',
                $claim->booking?->booking_number ?? '-',
                $claim->booking?->store?->name ?? '-',
                $negative($claim->appliedDiscount()),
            ];
        }
        if (count($rows) >= $first) {
            $this->moneyRanges[] = 'E' . $first . ':E' . count($rows);
        }

        $rows[] = [];
        $rows[] = ['RINGKASAN PROMO TOTAL PEMBELIAN'];
        $rows[] = ['Total Transaksi', $r['spendPromoTransactionCount']];
        $rows[] = ['Total Potongan', $negative($r['spendPromoDiscountTotal'])];
        $this->moneyRanges[] = 'B' . count($rows);
        $rows[] = [];
        $rows[] = ['DETAIL TRANSAKSI PROMO TOTAL PEMBELIAN'];
        $rows[] = ['Tanggal', 'Promo', 'No. Booking', 'Toko', 'Potongan'];

        $first = count($rows) + 1;
        foreach ($r['spendPromoBookings'] as $booking) {
            /** @var Booking $booking */
            $rows[] = [
                $booking->created_at?->format('Y-m-d'),
                $booking->spendPromo?->name ?? '-',
                $booking->booking_number,
                $booking->store?->name ?? '-',
                $negative($booking->spend_promo_discount),
            ];
        }
        if (count($rows) >= $first) {
            $this->moneyRanges[] = 'E' . $first . ':E' . count($rows);
        }

        $rows[] = [];
        $rows[] = ['PERFORMA VOUCHER (company-wide)'];
        $rows[] = ['Voucher', 'Diklaim', 'Dipakai', 'Sisa Stok', 'Status'];
        foreach ($r['vouchers'] as $voucher) {
            $rows[] = [
                $voucher->name,
                $voucher->claimed_in_period,
                $voucher->used_in_period,
                $voucher->remainingStock(),
                $voucher->is_active ? 'Aktif' : 'Nonaktif',
            ];
        }

        $rows[] = [];
        $rows[] = ['PERFORMA REWARD (company-wide)'];
        $rows[] = ['Reward', 'Ditukar', 'Terpenuhi', 'Poin Terpakai', 'Sisa Stok'];
        foreach ($r['rewards'] as $reward) {
            $rows[] = [
                $reward->name,
                $reward->redeemed_in_period,
                $reward->fulfilled_in_period,
                $reward->points_spent_in_period ?? 0,
                $reward->stock === null ? 'Tanpa batas' : $reward->stock,
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        if ($this->moneyRanges === []) {
            $this->array();
        }

        $styles = [1 => ['font' => ['bold' => true, 'size' => 14]]];
        foreach ($this->moneyRanges as $range) {
            $styles[$range] = ['numberFormat' => ['formatCode' => self::MONEY]];
        }

        return $styles;
    }
}

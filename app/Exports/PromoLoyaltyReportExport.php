<?php

namespace App\Exports;

use App\Models\VoucherClaim;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Promo" / "Laporan Kupon" (PromoLoyaltyReport /
 * CouponReport — logic sama, cuma nama menu beda) — audit 2026-09-11,
 * temuan B. $title dipakai supaya file export dari "Laporan Kupon"
 * tidak keliru bertuliskan "Laporan Promo".
 */
class PromoLoyaltyReportExport implements FromArray, WithStyles
{
    public function __construct(private array $result, private string $title = 'Laporan Promo') {}

    public function array(): array
    {
        $r = $this->result;
        $rupiah = fn ($n) => number_format((float) $n, 0, ',', '.');

        $rows = [
            [$this->title],
            ['Periode', $r['from']->format('d M Y') . ' - ' . $r['to']->format('d M Y')],
            [],
            ['RINGKASAN TRANSAKSI PROMO'],
            ['Total Transaksi dengan Promo', $r['promoTransactionCount']],
            ['Nilai Promo', '(' . $rupiah($r['promoValue']) . ')'],
            ['Total Penjualan dengan Promo', $rupiah($r['promoSalesTotal'])],
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

        foreach ($r['usedClaims'] as $claim) {
            /** @var VoucherClaim $claim */
            $rows[] = [
                optional($claim->used_at)->format('Y-m-d'),
                $claim->voucher?->name ?? '-',
                $claim->booking?->booking_number ?? '-',
                $claim->booking?->store?->name ?? '-',
                '(' . $rupiah($claim->voucher->discount_amount ?? 0) . ')',
            ];
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
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
        ];
    }
}

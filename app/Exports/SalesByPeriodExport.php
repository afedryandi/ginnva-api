<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Penjualan Per Periode" (SalesByPeriodReport) — audit
 * 2026-09-11, temuan B (halaman ini sebelumnya tidak punya export, beda
 * dari Ringkasan Penjualan & Detail Penjualan). FromArray (bukan
 * FromQuery) karena sumbernya hasil agregasi getResult(), pola sama
 * dengan SalesSummaryExport — baris di file SAMA PERSIS dengan yang
 * tampil di layar untuk rentang/granularitas yang sama.
 *
 * 2026-11-04: kolom HPP dan Laba Kotor dihapus (HPP dipegang Accurate); kolom Diterima dan Piutang hanya ada bila modul Keuangan menyala;
 * semua nominal tetap ANGKA (tanda "belum lengkap" pindah ke kolom Catatan, bukan teks di sel angka); ada baris Total di akhir.
 */
class SalesByPeriodExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        $headings = ['Periode', 'Transaksi', 'Penjualan'];

        if (config('features.finance')) {
            array_push($headings, 'Diterima', 'Piutang');
        }

        array_push($headings, 'Produk', 'Pengembalian', 'Komisi', 'Penjualan/Transaksi', 'Produk/Transaksi', 'Catatan');

        return $headings;
    }

    public function array(): array
    {
        $finance = (bool) config('features.finance');

        $rows = collect($this->result['rows'])->map(function (array $row) use ($finance) {
            $line = [$row['label'], $row['count'], $row['revenue']];

            if ($finance) {
                array_push($line, $row['received'], $row['outstanding']);
            }

            array_push(
                $line,
                $row['products'],
                $row['refund'],
                $row['commission'],
                $row['count'] > 0 ? round($row['revenue'] / $row['count']) : 0,
                $row['count'] > 0 ? round($row['products'] / $row['count'], 2) : 0,
                $row['hasUnratedJob'] ? 'Komisi belum lengkap (ada installer tanpa tarif)' : ''
            );

            return $line;
        })->values()->all();

        $total = ['Total', $this->result['totalCount'], $this->result['totalRevenue']];

        if ($finance) {
            array_push($total, $this->result['totalReceived'], $this->result['totalOutstanding']);
        }

        array_push(
            $total,
            $this->result['totalProducts'],
            $this->result['totalRefund'],
            $this->result['totalCommission'],
            $this->result['totalCount'] > 0 ? round($this->result['totalRevenue'] / $this->result['totalCount']) : 0,
            $this->result['totalCount'] > 0 ? round($this->result['totalProducts'] / $this->result['totalCount'], 2) : 0,
            ''
        );

        $rows[] = $total;

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1F2937'],
                ],
            ],
        ];
    }
}

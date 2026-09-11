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
 */
class SalesByPeriodExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Periode',
            'Transaksi',
            'Penjualan',
            'Diterima',
            'Piutang',
            'Produk',
            'Pengembalian',
            'Komisi',
            'Penjualan/Transaksi',
            'Produk/Transaksi',
        ];
    }

    public function array(): array
    {
        return collect($this->result['rows'])
            ->map(fn (array $row) => [
                $row['label'],
                $row['count'],
                $row['revenue'],
                $row['received'],
                $row['outstanding'],
                $row['products'],
                $row['refund'],
                $row['commission'] . ($row['hasUnratedJob'] ? ' *' : ''),
                $row['count'] > 0 ? round($row['revenue'] / $row['count']) : 0,
                $row['count'] > 0 ? round($row['products'] / $row['count'], 2) : 0,
            ])
            ->values()
            ->all();
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

<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Penjualan Outlet" (SalesByOutletReport) — audit 2026-09-11,
 * temuan B. FromArray karena sumbernya hasil agregasi getResult(), pola
 * sama SalesSummaryExport/SalesByPeriodExport.
 */
class SalesByOutletExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Outlet',
            'Transaksi',
            'Penjualan (bersih)',
            'Penjualan %',
            'Pengembalian',
            'Produk',
            'Produk %',
            'Rata-rata/Transaksi',
            'Produk/Transaksi',
            'Piutang',
        ];
    }

    public function array(): array
    {
        return collect($this->result['rows'])
            ->map(fn (array $row) => [
                $row['store']->name,
                $row['count'],
                $row['revenue'],
                round($row['revenuePct'], 1) . '%',
                $row['refund'],
                $row['products'],
                round($row['productsPct'], 1) . '%',
                round($row['avg']),
                round($row['productsPerTransaction'], 2),
                $row['outstanding'],
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

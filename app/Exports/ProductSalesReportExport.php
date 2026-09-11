<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Penjualan Produk" (ProductSalesReport) — audit 2026-09-11,
 * temuan B.
 */
class ProductSalesReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Produk',
            'SKU',
            'Jenis Produk',
            'Jumlah',
            'Jumlah %',
            'Penjualan',
            'Penjualan %',
            'Jumlah Refund',
            'Refund',
        ];
    }

    public function array(): array
    {
        return collect($this->result['rows'])
            ->map(fn (array $row) => [
                $row['name'],
                $row['sku'],
                $row['type'],
                $row['count'],
                round($row['countPct'], 1) . '%',
                $row['revenue'],
                round($row['revenuePct'], 1) . '%',
                $row['refundCount'],
                $row['refundAmount'],
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

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
            'Komisi',
            'HPP',
            'Laba Kotor',
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
                // Persentase berupa angka (judul kolom sudah memuat %), bukan teks, supaya bisa dijumlah/difilter.
                round($row['countPct'], 1),
                $row['revenue'],
                round($row['revenuePct'], 1),
                $row['refundCount'],
                $row['refundAmount'],
                // Angka tetap numerik; hanya baris yang datanya belum lengkap berubah jadi teks bertanda " *".
                $row['hasUnratedJob'] ? $row['commission'] . ' *' : $row['commission'],
                $row['hasMissingCost'] ? $row['cogs'] . ' *' : $row['cogs'],
                $row['grossProfit'],
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

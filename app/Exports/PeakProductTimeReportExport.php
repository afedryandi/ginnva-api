<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Waktu Teramai Produk" (PeakProductTimeReport) — audit
 * 2026-09-12, temuan B.
 */
class PeakProductTimeReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return ['Produk', 'Hari', 'Jumlah', 'Jumlah (%)', 'Penjualan (Rp)', 'Penjualan (%)'];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->result['rows'] as $row) {
            $rows[] = [
                $row['product'] ? "{$row['product']->sku} - {$row['product']->name}" : 'Belum Diisi SKU',
                $row['dayName'],
                $row['count'],
                round($row['countPct'], 1),
                $row['revenue'],
                round($row['revenuePct'], 1),
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2937']],
            ],
        ];
    }
}

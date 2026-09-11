<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Waktu Teramai Penjualan" (PeakSalesTimeReport) — audit
 * 2026-09-11, temuan B.
 */
class PeakSalesTimeReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return ['Hari', 'Penjualan (Rp)', 'Penjualan (%)', 'Transaksi', 'Transaksi (%)', 'Produk', 'Produk (%)', 'Pelanggan'];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->result['rows'] as $row) {
            $rows[] = [
                $row['dayName'],
                $row['revenue'],
                round($row['revenuePct'], 1),
                $row['count'],
                round($row['countPct'], 1),
                $row['products'],
                round($row['productsPct'], 1),
                $row['customers'],
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

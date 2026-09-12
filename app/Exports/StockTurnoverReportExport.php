<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Perputaran Stok" (StockTurnoverReport) — audit 2026-09-12,
 * temuan B.
 */
class StockTurnoverReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return ['Nama', 'Jenis', 'Terpakai', 'Sisa', 'Rata-rata Stok', 'Perputaran Stok', 'Hari Terjual'];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->result['rows'] as $row) {
            $rows[] = [
                $row['item']->name . ' (' . $row['item']->unit . ')',
                $row['type'],
                $row['qtyOut'],
                $row['stockAtTo'],
                $row['avgStock'],
                $row['turnoverRatio'] !== null ? round($row['turnoverRatio'], 2) : '-',
                $row['daysSold'],
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

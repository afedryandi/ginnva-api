<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Daftar Stok" (StockCardReport) — audit 2026-09-14, temuan B.
 */
class StockCardReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return ['Kode', 'Nama', 'Jenis', 'Awal', 'Masuk', 'Keluar', 'Akhir', 'Satuan'];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->result['rows'] as $row) {
            $rows[] = [
                $row['code'] ?: '-',
                $row['name'],
                $row['jenis'],
                $row['awal'],
                $row['masuk'],
                $row['keluar'],
                $row['akhir'],
                $row['unit'],
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

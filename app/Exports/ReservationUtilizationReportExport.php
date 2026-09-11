<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Reservasi & Utilisasi" (ReservationUtilizationReport)
 * — audit 2026-09-11, temuan B.
 */
class ReservationUtilizationReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Toko',
            'Hari Kerja',
            'Kapasitas/Hari',
            'Total Kapasitas',
            'Terpakai',
            'Utilisasi %',
        ];
    }

    public function array(): array
    {
        return collect($this->result['rows'])
            ->map(fn (array $row) => [
                $row['store']->name,
                $row['workingDays'],
                $row['capacityPerDay'],
                $row['totalCapacity'],
                $row['totalUsed'],
                round($row['utilizationPct'], 1) . '%',
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

<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Komisi Teknisi" / "Komisi Tetap" — audit 2026-09-11,
 * temuan B. $title dipakai supaya file export dari "Komisi Tetap" tidak
 * keliru bertuliskan "Laporan Komisi Teknisi".
 */
class TechnicianCommissionReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result, private string $title = 'Komisi Teknisi') {}

    public function headings(): array
    {
        return [
            'Teknisi',
            'Toko',
            'Jumlah Pekerjaan',
            'Penjualan',
            'Komisi/Pekerjaan',
            'Total Komisi',
        ];
    }

    public function array(): array
    {
        return collect($this->result['rows'])
            ->map(fn (array $row) => [
                $row['technician']->name,
                $row['technician']->store?->name ?? '-',
                $row['jobCount'],
                $row['salesTotal'],
                $row['rate'] ?? 'Belum diatur',
                $row['totalCommission'] ?? 'Belum diatur',
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

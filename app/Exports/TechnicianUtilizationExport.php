<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Utilisasi Teknisi" (TechnicianUtilizationReport).
 * FromArray karena sumbernya Collection hasil TechnicianUtilizationService::summarize()
 * (getRows()) — barisnya MENIRU PERSIS apa yang tampil di layar (lihat
 * resources/views/filament/pages/technician-utilization-report.blade.php),
 * termasuk "Belum ada akun" untuk teknisi tanpa akun login (bukan dikonversi
 * jadi 0, supaya tidak menyesatkan kalau dibuka terpisah tanpa konteks
 * halaman webnya).
 */
class TechnicianUtilizationExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Teknisi',
            'Cabang',
            'Jam Hadir',
            'Jam Job (Estimasi)',
            'Jam Idle',
            'Utilisasi (%)',
        ];
    }

    public function array(): array
    {
        return collect($this->result['rows'])
            ->map(fn (array $row) => [
                $row['name'],
                $row['store_name'] ?? '-',
                $row['has_account'] ? (float) $row['present_hours'] : 'Belum ada akun',
                (float) $row['job_hours'],
                $row['idle_hours'] !== null ? (float) $row['idle_hours'] : '-',
                $row['utilization_percent'] !== null ? (float) $row['utilization_percent'] : '-',
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
                    'startColor' => ['rgb' => '374151'],
                ],
            ],
        ];
    }
}

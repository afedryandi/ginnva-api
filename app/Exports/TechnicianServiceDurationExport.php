<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Akumulasi Durasi Servis Teknisi" (TechnicianServiceDurationReport).
 * FromArray karena sumbernya Collection hasil TechnicianServiceDurationService::summarize()
 * (getRows()), bukan Eloquent Builder biasa — barisnya MENIRU PERSIS apa yang
 * tampil di layar (lihat resources/views/filament/pages/technician-service-duration-report.blade.php).
 */
class TechnicianServiceDurationExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Teknisi',
            'Cabang',
            'Jumlah Job',
            'Total Durasi (Jam)',
            'Rata-rata per Job (Menit)',
        ];
    }

    public function array(): array
    {
        return collect($this->result['rows'])
            ->map(fn (array $row) => [
                $row['name'],
                $row['store_name'] ?? '-',
                $row['job_count'],
                (float) $row['total_hours'],
                $row['job_count'] > 0 ? $row['avg_minutes_per_job'] : '-',
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

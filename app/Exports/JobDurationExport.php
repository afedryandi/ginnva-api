<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Proses Order" (JobDurationReport) -- 1 baris = 1 job
 * (1 SPK), sama data mentah dengan tabel yang tampil di layar
 * (JobDurationService::jobs()).
 *
 * @param array{jobs: \Illuminate\Support\Collection<int, array<string, mixed>>} $result
 */
class JobDurationExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Tanggal',
            'No. SPK',
            'Cabang',
            'Customer',
            'Jenis Layanan',
            'Teknisi',
            'Durasi (menit)',
        ];
    }

    public function array(): array
    {
        return collect($this->result['jobs'])
            ->map(fn (array $job) => [
                $job['date']->format('Y-m-d H:i'),
                $job['spk_number'],
                $job['store_name'] ?? '-',
                $job['customer_name'],
                implode(', ', $job['services']),
                ! empty($job['technicians']) ? implode(', ', $job['technicians']) : '-',
                (int) $job['minutes'],
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
                    'startColor' => ['rgb' => '1D4ED8'],
                ],
            ],
        ];
    }
}

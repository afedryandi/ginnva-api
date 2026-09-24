<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Proses Produk" (JobDurationByServiceReport) -- 1 baris
 * = 1 jenis layanan (agregat), sama data dengan
 * JobDurationService::aggregateByService().
 *
 * @param array{rows: \Illuminate\Support\Collection<int, array<string, mixed>>} $result
 */
class JobDurationByServiceExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Jenis Layanan',
            'Jumlah Job',
            'Rata-rata (menit)',
            'Tercepat (menit)',
            'Terlama (menit)',
        ];
    }

    public function array(): array
    {
        return collect($this->result['rows'])
            ->map(fn (array $row) => [
                $row['service'],
                (int) $row['jobCount'],
                round($row['avgMinutes'], 1),
                (int) $row['minMinutes'],
                (int) $row['maxMinutes'],
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

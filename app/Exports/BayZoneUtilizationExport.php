<?php

namespace App\Exports;

use App\Services\BayZoneUtilizationService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Utilisasi Zona/Bay" (BayZoneUtilizationReport, f18).
 * FromArray karena sumbernya array hasil BayZoneUtilizationService, bukan
 * Eloquent Builder biasa — sama pola dengan VoidReportExport.
 */
class BayZoneUtilizationExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Zona',
            'Kapasitas (Slot)',
            'Jumlah Booking Lewat Zona Ini',
            'Jam Tersedia',
            'Jam Terpakai',
            'Utilisasi %',
            'Peak Bersamaan',
        ];
    }

    public function array(): array
    {
        return collect($this->result['zones'])
            ->map(function (array $zone) {
                if (! $zone['configured']) {
                    return [
                        $zone['label'],
                        'Belum dikonfigurasi',
                        '-',
                        '-',
                        '-',
                        '-',
                        '-',
                    ];
                }

                return [
                    $zone['label'],
                    $zone['slotCount'],
                    $zone['bookingCount'],
                    round($zone['availableHours'], 1),
                    round($zone['occupiedHours'], 1),
                    round($zone['utilizationPct'], 1),
                    $zone['peakConcurrent'],
                ];
            })
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

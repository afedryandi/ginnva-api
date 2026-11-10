<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Rekap per Karyawan" dari Laporan Absensi (2026-11-10): satu baris per karyawan untuk rentang yang dipilih, siap dikirim ke
 * HR atau Accurate setelah gaji tidak dihitung lagi di sistem ini. Angkanya dari baris Absensi mentah (AttendanceReport::summaryByEmployee()).
 */
class AttendanceSummaryExport implements FromArray, WithHeadings, WithStyles
{
    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private array $rows) {}

    public function headings(): array
    {
        return [
            'Karyawan',
            'Toko',
            'Hadir (Hari)',
            'Terlambat (Kali)',
            'Telat (Menit)',
            'Pulang Cepat (Kali)',
            'Alpha (Hari)',
            'Izin/Cuti (Hari)',
        ];
    }

    public function array(): array
    {
        return collect($this->rows)
            ->map(fn (array $row) => [
                $row['name'],
                $row['store'],
                $row['present'],
                $row['late_count'],
                $row['late_minutes'],
                $row['early_leave_count'],
                $row['alpha'],
                $row['leave'],
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

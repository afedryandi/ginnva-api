<?php

namespace App\Exports;

use App\Models\Attendance;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Absensi" (AttendanceReport) — audit 2026-09-11,
 * temuan B.
 */
class AttendanceReportExport implements FromArray, WithHeadings, WithStyles
{
    private const ENTRY_TYPE_LABEL = [
        'clock' => 'Clock In/Out',
        'manual' => 'Manual',
        'field_duty' => 'Tugas Lapangan',
        'alpha' => 'Alpha',
        'leave' => 'Izin/Cuti',
    ];

    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Nama',
            'Toko',
            'Tanggal',
            'Absen Masuk',
            'Absen Keluar',
            'Terlambat (Menit)',
            'Pulang Cepat (Menit)',
            'Jenis',
        ];
    }

    public function array(): array
    {
        return collect($this->result['rows'])
            ->map(fn (Attendance $row) => [
                $row->user?->name ?? '-',
                $row->store?->name ?? '-',
                $row->date?->format('Y-m-d'),
                $row->clock_in_at?->format('H:i') ?? '-',
                $row->clock_out_at?->format('H:i') ?? '-',
                $row->late_minutes,
                $row->early_leave_minutes,
                self::ENTRY_TYPE_LABEL[$row->entry_type] ?? $row->entry_type,
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

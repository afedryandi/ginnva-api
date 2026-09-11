<?php

namespace App\Exports;

use App\Models\Payroll;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Karyawan" (EmployeeReport) — audit 2026-09-11, temuan
 * B. Berisi gaji bersih (net_pay) — data sensitif, halaman sumbernya
 * sudah dibatasi isFullAccess() saja.
 */
class EmployeeReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Karyawan',
            'Toko',
            'Hari Kerja',
            'Telat (Menit)',
            'Alpha (Hari)',
            'Gaji Bersih',
            'Status',
        ];
    }

    public function array(): array
    {
        return collect($this->result['payrolls'])
            ->map(fn (Payroll $payroll) => [
                $payroll->user?->name ?? '-',
                $payroll->store?->name ?? '-',
                $payroll->working_days_in_month,
                $payroll->total_late_minutes,
                $payroll->alpha_days,
                (float) $payroll->net_pay,
                $payroll->status === 'paid' ? 'Sudah Dibayar' : 'Draft',
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

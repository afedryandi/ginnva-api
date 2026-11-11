<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Rekap per Karyawan" dari Laporan Absensi (2026-11-10): satu baris per karyawan untuk rentang yang dipilih, siap dikirim ke
 * HR atau Accurate setelah gaji tidak dihitung lagi di sistem ini. Angkanya dari baris Absensi mentah (AttendanceReport::summaryByEmployee()).
 */
class AttendanceSummaryExport implements FromArray, WithHeadings, WithStyles, WithTitle
{
    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private array $rows, private Carbon $from, private Carbon $to) {}

    /** Nama sheet memuat rentang tanggal supaya HR tahu rekap ini untuk periode mana (batas Excel 31 karakter). */
    public function title(): string
    {
        return 'Rekap ' . $this->from->format('Ymd') . '-' . $this->to->format('Ymd');
    }

    public function headings(): array
    {
        return [
            'Karyawan',
            'Toko',
            'Hadir (Hari)',
            'Terlambat (Kali)',
            'Telat (Menit)',
            'Pulang Cepat (Kali)',
            'Total Jam Kerja',
            'Belum Absen Keluar (Hari)',
            'Alpha (Hari)',
            'Izin/Cuti (Hari)',
        ];
    }

    public function array(): array
    {
        $lines = collect($this->rows)
            ->map(fn (array $row) => [
                $row['name'],
                $row['store'],
                $row['present'],
                $row['late_count'],
                $row['late_minutes'],
                $row['early_leave_count'],
                $row['work_hours'],
                $row['missing_clock_out'],
                $row['alpha'],
                $row['leave'],
            ])
            ->values();

        // Baris total (kolom angka mulai dari Hadir); kosong bila tidak ada karyawan.
        if ($lines->isNotEmpty()) {
            $total = ['TOTAL', ''];
            foreach (range(2, 9) as $col) {
                $total[] = round($lines->sum(fn (array $line) => (float) $line[$col]), 2);
            }
            $lines->push($total);
        }

        return $lines->all();
    }

    public function styles(Worksheet $sheet): array
    {
        $last = $sheet->getHighestRow();

        return [
            // Baris total dicetak tebal (bila ada data di bawah judul kolom).
            $last > 1 ? $last : 1 => ['font' => ['bold' => true]],
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

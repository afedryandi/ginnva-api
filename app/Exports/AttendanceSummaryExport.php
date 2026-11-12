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
 *
 * 2026-11-12: Jam Lembur Disetujui (hanya jam dari Pengajuan Lembur yang disetujui) dan Jam Luar Jam Resmi Tanpa Persetujuan (kerja setelah jam
 * tutup atau di hari libur toko tanpa persetujuan), supaya HR tahu mana lembur yang sah dan mana yang perlu ditegur.
 *
 * 2026-11-11: kolom No. Karyawan (nomor induk internal, bukan NIK KTP) untuk mencocokkan dengan Accurate tanpa bergantung pada ejaan nama, dan
 * pecahan Izin/Cuti per jenis (satu kolom per jenis yang muncul pada rentang itu, di kanan kolom total Izin/Cuti).
 */
class AttendanceSummaryExport implements FromArray, WithHeadings, WithStyles, WithTitle
{
    /** Kolom tetap: kunci data => judul. Kolom angka dijumlah di baris TOTAL; 'work_hours' berdesimal, sisanya bilangan bulat. */
    private const COLUMNS = [
        'employee_number' => 'No. Karyawan',
        'name' => 'Karyawan',
        'store' => 'Toko',
        'present' => 'Hadir (Hari)',
        'late_count' => 'Terlambat (Kali)',
        'late_minutes' => 'Telat (Menit)',
        'early_leave_count' => 'Pulang Cepat (Kali)',
        'work_hours' => 'Total Jam Kerja',
        'overtime_hours' => 'Jam Lembur Disetujui',
        'unapproved_hours' => 'Jam Luar Jam Resmi Tanpa Persetujuan',
        'missing_clock_out' => 'Belum Absen Keluar (Hari)',
        'alpha' => 'Alpha (Hari)',
        'leave' => 'Izin/Cuti (Hari)',
    ];

    private const TEXT_COLUMNS = ['employee_number', 'name', 'store'];

    /** Kolom berdesimal (jam); kolom angka lain bilangan bulat. */
    private const DECIMAL_COLUMNS = ['work_hours', 'overtime_hours', 'unapproved_hours'];

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private array $rows, private Carbon $from, private Carbon $to) {}

    /** Nama sheet memuat rentang tanggal supaya HR tahu rekap ini untuk periode mana (batas Excel 31 karakter). */
    public function title(): string
    {
        return 'Rekap ' . $this->from->format('Ymd') . '-' . $this->to->format('Ymd');
    }

    /** Jenis izin/cuti yang muncul pada rentang ini, urut abjad (satu kolom per jenis). */
    public function leaveTypes(): array
    {
        return collect($this->rows)
            ->flatMap(fn (array $row) => array_keys($row['leave_by_type'] ?? []))
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function headings(): array
    {
        return array_merge(array_values(self::COLUMNS), array_map(fn (string $type) => "  - {$type} (Hari)", $this->leaveTypes()));
    }

    public function array(): array
    {
        $types = $this->leaveTypes();

        $lines = collect($this->rows)
            ->map(function (array $row) use ($types) {
                $line = [];
                foreach (array_keys(self::COLUMNS) as $key) {
                    $line[] = $row[$key] ?? ($key === 'employee_number' ? '-' : '');
                }
                foreach ($types as $type) {
                    $line[] = (int) ($row['leave_by_type'][$type] ?? 0);
                }

                return $line;
            })
            ->values();

        // Baris total; kosong bila tidak ada karyawan.
        if ($lines->isNotEmpty()) {
            $keys = array_keys(self::COLUMNS);
            $total = [];

            foreach ($keys as $index => $key) {
                if (in_array($key, self::TEXT_COLUMNS, true)) {
                    $total[] = $key === 'name' ? 'TOTAL' : '';

                    continue;
                }

                $sum = $lines->sum(fn (array $line) => (float) $line[$index]);
                $total[] = in_array($key, self::DECIMAL_COLUMNS, true) ? round($sum, 2) : (int) round($sum);
            }

            foreach (array_keys($types) as $offset) {
                $total[] = (int) round($lines->sum(fn (array $line) => (float) $line[count($keys) + $offset]));
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

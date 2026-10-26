<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Ekspor Excel untuk laporan berbentuk tabel sederhana (judul kolom, baris, dan satu baris total opsional): Saldo Kas & Bank,
 * Anggaran vs Realisasi, Rekonsiliasi Sub-Buku. Angka diekspor sebagai angka (bukan teks berformat), supaya bisa dijumlah di Excel.
 * Data dari ExportsReportTable::reportExportData().
 */
class SimpleTableExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize
{
    /** @param array{headings: list<string>, rows: list<list<mixed>>, totals?: ?list<mixed>} $data */
    public function __construct(private array $data) {}

    public function headings(): array
    {
        return $this->data['headings'];
    }

    public function array(): array
    {
        $rows = $this->data['rows'];

        if (! empty($this->data['totals'])) {
            $rows[] = $this->data['totals'];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
            ],
        ];

        if (! empty($this->data['totals'])) {
            $styles[count($this->data['rows']) + 2] = ['font' => ['bold' => true]];
        }

        return $styles;
    }
}

<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Ekspor Excel untuk laporan ringkasan yang terdiri dari beberapa tabel (SLA & Tidak Datang, Dasbor Lead, Kompetensi Installer):
 * satu lembar, tiap bagian diawali judul dan baris judul kolom, dipisah satu baris kosong. Data dari ExportsReportTable::reportExportData()
 * bentuk 'sections'.
 */
class SectionedTableExport implements FromArray, WithStyles, ShouldAutoSize
{
    /** @param array{sections: list<array{title?: ?string, headings: list<string>, rows: list<list<mixed>>, totals?: ?list<mixed>}>} $data */
    public function __construct(private array $data) {}

    /** @return array{0: list<list<mixed>>, 1: list<int>, 2: list<int>, 3: list<int>} baris, nomor baris judul bagian, judul kolom, total */
    private function layout(): array
    {
        $lines = [];
        $titles = [];
        $headings = [];
        $totals = [];

        foreach ($this->data['sections'] as $section) {
            if (! empty($section['title'])) {
                $lines[] = [$section['title']];
                $titles[] = count($lines);
            }

            $lines[] = $section['headings'];
            $headings[] = count($lines);

            foreach ($section['rows'] as $row) {
                $lines[] = $row;
            }

            if ($section['rows'] === []) {
                $lines[] = ['Tidak ada data.'];
            }

            if (! empty($section['totals'])) {
                $lines[] = $section['totals'];
                $totals[] = count($lines);
            }

            $lines[] = [];
        }

        return [$lines, $titles, $headings, $totals];
    }

    public function array(): array
    {
        return $this->layout()[0];
    }

    public function styles(Worksheet $sheet): array
    {
        [, $titles, $headings, $totals] = $this->layout();
        $styles = [];

        foreach ($titles as $row) {
            $styles[$row] = ['font' => ['bold' => true, 'size' => 13]];
        }

        foreach ($headings as $row) {
            $styles[$row] = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
            ];
        }

        foreach ($totals as $row) {
            $styles[$row] = ['font' => ['bold' => true]];
        }

        return $styles;
    }
}

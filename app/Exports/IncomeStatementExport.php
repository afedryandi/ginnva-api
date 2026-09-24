<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Laba Rugi" (IncomeStatementReport) ke Excel. FromArray
 * karena sumbernya array hasil FinancialStatementService::incomeStatement()
 * (sections berisi model Account + amount per baris), bukan Eloquent
 * Builder biasa. Baris section & subtotal (Laba Kotor/Operasional/
 * Sebelum Pajak/Bersih) di-bold lewat styles() -- posisinya dihitung
 * dinamis (bukan hardcoded) karena jumlah akun tiap section berbeda
 * tiap periode.
 */
class IncomeStatementExport implements FromArray, WithStyles
{
    /** @var int[] */
    private array $sectionRowIndexes = [];

    /** @var int[] */
    private array $subtotalRowIndexes = [];

    public function __construct(private array $result) {}

    public function array(): array
    {
        $r = $this->result;
        $sections = $r['sections'];

        $rows = [
            ['Laporan Laba Rugi'],
            ['Periode', $r['from']->format('d M Y') . ' - ' . $r['to']->format('d M Y')],
            [],
        ];

        $addSection = function (array $section) use (&$rows) {
            $this->sectionRowIndexes[] = count($rows) + 1;
            $rows[] = [strtoupper($section['label'])];
            foreach ($section['rows'] as $row) {
                $rows[] = ['    ' . $row['account']->name, (float) $row['amount']];
            }
            $this->subtotalRowIndexes[] = count($rows) + 1;
            $rows[] = ['Total ' . $section['label'], (float) $section['total']];
            $rows[] = [];
        };

        $addSection($sections['pendapatan']);
        $addSection($sections['beban_pokok']);

        $this->subtotalRowIndexes[] = count($rows) + 1;
        $rows[] = ['Laba Kotor', (float) $r['laba_kotor']];
        $rows[] = [];

        $addSection($sections['beban_operasional']);

        $this->subtotalRowIndexes[] = count($rows) + 1;
        $rows[] = ['Laba Operasional', (float) $r['laba_operasional']];
        $rows[] = [];

        $addSection($sections['pendapatan_lain']);
        $addSection($sections['beban_lain']);

        $this->subtotalRowIndexes[] = count($rows) + 1;
        $rows[] = ['Laba Sebelum Pajak', (float) $r['laba_sebelum_pajak']];
        $rows[] = [];

        $addSection($sections['pajak']);

        $this->subtotalRowIndexes[] = count($rows) + 1;
        $rows[] = ['Laba Bersih', (float) $r['laba_bersih']];

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        // array() dipanggil dulu oleh package Excel sebelum styles(),
        // jadi $sectionRowIndexes/$subtotalRowIndexes sudah terisi.
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
        ];

        foreach ($this->sectionRowIndexes as $rowNumber) {
            $styles[$rowNumber] = ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]];
            $styles["A{$rowNumber}:B{$rowNumber}"] = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '166534']]];
        }

        foreach ($this->subtotalRowIndexes as $rowNumber) {
            $styles[$rowNumber] = ['font' => ['bold' => true]];
        }

        return $styles;
    }
}

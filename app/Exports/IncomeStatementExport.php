<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
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
 *
 * Audit Laba Rugi 2026-09-29: mengikuti halaman -- kolom % dari pendapatan, kolom pembanding
 * (+ selisih %) kalau dipilih, subtotal per akun induk, label akun kontra, dan nama toko.
 */
class IncomeStatementExport implements FromArray, WithStyles, WithColumnWidths
{
    /** @var int[] */
    private array $sectionRowIndexes = [];

    /** @var int[] */
    private array $subtotalRowIndexes = [];

    /** @var int[] */
    private array $headerRowIndexes = [];

    private bool $hasCompare;

    private ?array $prevMap = null;

    private float $revenue;

    public function __construct(private array $result)
    {
        $this->hasCompare = ! empty($result['compare']);
        $this->revenue = (float) $result['sections']['pendapatan']['total'];

        if ($this->hasCompare) {
            $this->prevMap = [];
            foreach ($result['compare']['sections'] as $section) {
                foreach ($section['rows'] as $row) {
                    $this->prevMap[$row['account']->id] = (float) $row['amount'];
                }
            }
        }
    }

    private function lastColumn(): string
    {
        return $this->hasCompare ? 'E' : 'C';
    }

    private function pct(float $amount): float|string
    {
        return abs($this->revenue) < 0.005 ? '' : round($amount / $this->revenue * 100, 1);
    }

    private function delta(float $cur, ?float $prev): float|string
    {
        return ($prev === null || abs($prev) < 0.005) ? '' : round(($cur - $prev) / abs($prev) * 100, 1);
    }

    /** Baris data: [label, nilai, % pendapatan, (pembanding, selisih %)]. */
    private function line(string $label, float $amount, ?float $prev = null): array
    {
        $row = [$label, $amount, $this->pct($amount)];

        if ($this->hasCompare) {
            $row[] = $prev ?? '';
            $row[] = $prev === null ? '' : $this->delta($amount, $prev);
        }

        return $row;
    }

    public function array(): array
    {
        $r = $this->result;
        $sections = $r['sections'];
        $compare = $r['compare'] ?? null;

        $rows = [
            ['Laporan Laba Rugi'],
            ['Periode', $r['from']->format('d M Y') . ' - ' . $r['to']->format('d M Y')],
            ['Toko', $r['store_label'] ?? 'Semua Toko'],
        ];

        if ($this->hasCompare) {
            $rows[] = ['Pembanding', $r['compare_label'] ?? ''];
        }

        $rows[] = [];

        $this->headerRowIndexes[] = count($rows) + 1;
        $rows[] = $this->hasCompare
            ? ['Akun', 'Periode Ini', '% Pendapatan', 'Pembanding', 'Selisih %']
            : ['Akun', 'Periode Ini', '% Pendapatan'];

        $addSection = function (array $section, string $type) use (&$rows, $compare) {
            $this->sectionRowIndexes[] = count($rows) + 1;
            $rows[] = [strtoupper($section['label'])];

            $groups = $section['rows']->groupBy(fn ($row) => $row['account']->parent?->name ?? '');
            $showGroups = $groups->count() > 1 || ($groups->keys()->first() ?? '') !== '';

            foreach ($groups as $groupName => $items) {
                if ($showGroups && $groupName !== '') {
                    $rows[] = ['  ' . $groupName];
                }

                foreach ($items as $row) {
                    $name = '    ' . $row['account']->name . ($row['account']->is_contra ? ' (pengurang)' : '');
                    $prev = $this->prevMap !== null ? (float) ($this->prevMap[$row['account']->id] ?? 0) : null;
                    $rows[] = $this->line($name, (float) $row['amount'], $prev);
                }

                if ($showGroups && $groupName !== '' && $items->count() > 1) {
                    $rows[] = $this->line('  Subtotal ' . $groupName, (float) $items->sum('amount'));
                }
            }

            $this->subtotalRowIndexes[] = count($rows) + 1;
            $rows[] = $this->line('Total ' . $section['label'], (float) $section['total'], $compare ? (float) $compare['sections'][$type]['total'] : null);
            $rows[] = [];
        };

        $profit = function (string $label, string $key) use (&$rows, $r, $compare) {
            $this->subtotalRowIndexes[] = count($rows) + 1;
            $rows[] = $this->line($label, (float) $r[$key], $compare ? (float) $compare[$key] : null);
            $rows[] = [];
        };

        $addSection($sections['pendapatan'], 'pendapatan');
        $addSection($sections['beban_pokok'], 'beban_pokok');
        $profit('Laba Kotor', 'laba_kotor');

        $addSection($sections['beban_operasional'], 'beban_operasional');
        $profit('Laba Operasional', 'laba_operasional');

        $addSection($sections['pendapatan_lain'], 'pendapatan_lain');
        $addSection($sections['beban_lain'], 'beban_lain');
        $profit('Laba Sebelum Pajak', 'laba_sebelum_pajak');

        $addSection($sections['pajak'], 'pajak');
        $this->subtotalRowIndexes[] = count($rows) + 1;
        $rows[] = $this->line('Laba Bersih', (float) $r['laba_bersih'], $compare ? (float) $compare['laba_bersih'] : null);

        return $rows;
    }

    public function columnWidths(): array
    {
        return $this->hasCompare
            ? ['A' => 46, 'B' => 18, 'C' => 15, 'D' => 18, 'E' => 12]
            : ['A' => 46, 'B' => 18, 'C' => 15];
    }

    public function styles(Worksheet $sheet): array
    {
        // array() dipanggil dulu oleh package Excel sebelum styles(),
        // jadi $sectionRowIndexes/$subtotalRowIndexes sudah terisi.
        $last = $this->lastColumn();

        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
        ];

        foreach ($this->headerRowIndexes as $rowNumber) {
            $styles["A{$rowNumber}:{$last}{$rowNumber}"] = [
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
            ];
        }

        foreach ($this->sectionRowIndexes as $rowNumber) {
            $styles[$rowNumber] = ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]];
            $styles["A{$rowNumber}:{$last}{$rowNumber}"] = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '166534']]];
        }

        foreach ($this->subtotalRowIndexes as $rowNumber) {
            $styles[$rowNumber] = ['font' => ['bold' => true]];
        }

        return $styles;
    }
}

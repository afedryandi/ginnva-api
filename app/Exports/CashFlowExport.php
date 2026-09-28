<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Arus Kas" (CashFlowReport) ke Excel. FromArray karena
 * hasilnya array bersarang (sections -> groups -> rows) dari
 * FinancialStatementService::cashFlowStatement(), bukan Eloquent Builder.
 *
 * Audit Arus Kas 2026-09-29: semua nominal ditulis sebagai ANGKA (bukan teks) supaya bisa dijumlah/difilter,
 * baris total di-bold, peringatan klasifikasi dicantumkan, dan mengikuti halaman: pengelompokan menurut jenis
 * arus kas, kolom pembanding (+ selisih %), rincian per jurnal (kalau dipilih), rincian per akun kas, nama toko.
 */
class CashFlowExport implements FromArray, WithStyles, WithColumnWidths
{
    /** Nomor baris section (di-bold+fill) — dikumpulkan saat array() dibangun, dipakai lagi di styles(). */
    private array $sectionRows = [];

    /** @var int[] Baris total/ringkasan yang di-bold. */
    private array $boldRows = [];

    private bool $hasCompare;

    public function __construct(private array $result)
    {
        $this->hasCompare = ! empty($result['compare']);
    }

    private function delta(float $cur, ?float $prev): float|string
    {
        return ($prev === null || abs($prev) < 0.005) ? '' : round(($cur - $prev) / abs($prev) * 100, 1);
    }

    /** Baris nilai: [label, periode ini, (pembanding, selisih %)]. */
    private function line(string $label, float $cur, ?float $prev = null): array
    {
        $row = [$label, $cur];

        if ($this->hasCompare) {
            $row[] = $prev ?? '';
            $row[] = $prev === null ? '' : $this->delta($cur, $prev);
        }

        return $row;
    }

    public function array(): array
    {
        $r = $this->result;
        $compare = $r['compare'] ?? null;
        $rows = [];

        $this->sectionRows = [];
        $this->boldRows = [];

        $rows[] = ['Laporan Arus Kas'];
        $rows[] = ['Periode', $r['from']->format('d M Y') . ' - ' . $r['to']->format('d M Y')];
        $rows[] = ['Toko', $r['store_label'] ?? 'Semua Toko'];

        if ($this->hasCompare) {
            $rows[] = ['Pembanding', $r['compare_label'] ?? ''];
        }

        $rows[] = [];

        if ($this->hasCompare) {
            $rows[] = ['Uraian', 'Periode Ini', 'Pembanding', 'Selisih %'];
        }

        $this->boldRows[] = count($rows) + 1;
        $rows[] = $this->line('Saldo Kas Awal Periode', (float) $r['opening_cash'], $compare ? (float) $compare['opening_cash'] : null);
        $rows[] = [];

        $showDetails = (bool) ($r['show_details'] ?? false);

        foreach ($r['sections'] as $key => $section) {
            $this->sectionRows[] = count($rows) + 1;
            $rows[] = [$section['label']];

            $prevGroups = [];
            if ($compare) {
                foreach ($compare['sections'][$key]['groups'] as $g) {
                    $prevGroups[$g['label']] = (float) $g['total'];
                }
            }

            foreach ($section['groups'] as $group) {
                $rows[] = $this->line('  ' . $group['label'] . ' (' . $group['rows']->count() . ' jurnal)', (float) $group['total'], $compare ? ($prevGroups[$group['label']] ?? 0.0) : null);

                if ($showDetails) {
                    foreach ($group['rows'] as $row) {
                        $rows[] = [
                            '      ' . $row['entry_date']->format('d M Y') . ' — ' . $row['description'] . ' (' . $row['entry_number'] . ')',
                            (float) $row['amount'],
                        ];
                    }
                }
            }

            $this->boldRows[] = count($rows) + 1;
            $rows[] = $this->line('Total ' . $section['label'], (float) $section['total'], $compare ? (float) $compare['sections'][$key]['total'] : null);
            $rows[] = [];
        }

        $this->boldRows[] = count($rows) + 1;
        $rows[] = $this->line('Kenaikan (Penurunan) Kas Bersih', (float) $r['net_change'], $compare ? (float) $compare['net_change'] : null);
        $this->boldRows[] = count($rows) + 1;
        $rows[] = $this->line('Saldo Kas Akhir Periode', (float) $r['closing_cash'], $compare ? (float) $compare['closing_cash'] : null);
        $rows[] = [];
        $rows[] = [
            $r['is_reconciled']
                ? 'Sudah sesuai dengan saldo aktual akun kas (' . number_format((float) $r['closing_cash_actual'], 0, ',', '.') . ').'
                : 'Berbeda dari saldo aktual akun kas (' . number_format((float) $r['closing_cash_actual'], 0, ',', '.') . ') — periksa jurnal.',
        ];

        // Rincian per akun kas (untuk dicocokkan dengan rekening koran).
        if (! empty($r['cash_accounts'])) {
            $rows[] = [];
            $this->boldRows[] = count($rows) + 1;
            $rows[] = ['Rincian per Akun Kas', 'Saldo Awal', 'Mutasi', 'Saldo Akhir'];

            foreach ($r['cash_accounts'] as $ca) {
                $rows[] = [$ca['account']->display_name, (float) $ca['opening'], (float) $ca['mutation'], (float) $ca['closing']];
            }
        }

        // Peringatan klasifikasi ikut dicantumkan supaya penerima file tahu ada yang perlu dicek.
        if (! empty($r['warnings'])) {
            $rows[] = [];
            $this->boldRows[] = count($rows) + 1;
            $rows[] = ['Perlu diperiksa (klasifikasi arus kas):'];

            foreach ($r['warnings'] as $warning) {
                $rows[] = ['  - ' . $warning];
            }
        }

        return $rows;
    }

    public function columnWidths(): array
    {
        return $this->hasCompare
            ? ['A' => 80, 'B' => 18, 'C' => 18, 'D' => 14]
            : ['A' => 80, 'B' => 18, 'C' => 18, 'D' => 18];
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
        ];

        foreach ($this->boldRows as $rowNumber) {
            $styles[$rowNumber] = ['font' => ['bold' => true]];
        }

        foreach ($this->sectionRows as $rowNumber) {
            $styles[$rowNumber] = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
            ];
        }

        return $styles;
    }
}

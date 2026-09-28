<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Neraca" (BalanceSheetReport) ke Excel. FromArray karena
 * sumbernya array hasil FinancialStatementService::balanceSheet()
 * (rows Aset/Kewajiban/Modal berisi model Account + saldo), bukan
 * Eloquent Builder biasa. Baris section (Aset/Kewajiban/Modal) di-bold
 * lewat styles() -- posisinya dihitung dinamis (bukan hardcoded)
 * karena jumlah akun tiap section bisa berbeda tiap periode.
 *
 * Audit Neraca 2026-09-29: mengikuti halaman -- kolom % dari Total Aset, kolom pembanding (+ selisih %)
 * kalau dipilih, subtotal per akun induk, label akun kontra, nama toko, dan rasio keuangan.
 */
class BalanceSheetExport implements FromArray, WithStyles, WithColumnWidths
{
    /** @var array<string,int> */
    private array $sectionRowIndexes = [];

    /** @var int[] */
    private array $totalRowIndexes = [];

    /** @var int[] */
    private array $headerRowIndexes = [];

    private bool $hasCompare;

    private ?array $prevMap = null;

    private float $base;

    public function __construct(private array $result)
    {
        $this->hasCompare = ! empty($result['compare']);
        $this->base = (float) $result['aset']['total'];

        if ($this->hasCompare) {
            $this->prevMap = [];
            foreach (['aset', 'kewajiban', 'modal'] as $group) {
                foreach ($result['compare'][$group]['rows'] as $row) {
                    $this->prevMap[$row['account']->id] = (float) $row['balance'];
                }
            }
        }
    }

    private function pct(float $amount): float|string
    {
        return abs($this->base) < 0.005 ? '' : round($amount / $this->base * 100, 1);
    }

    private function delta(float $cur, ?float $prev): float|string
    {
        return ($prev === null || abs($prev) < 0.005) ? '' : round(($cur - $prev) / abs($prev) * 100, 1);
    }

    /** Baris data: [label, saldo, % total aset, (pembanding, selisih %)]. */
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
        $compare = $r['compare'] ?? null;

        $rows = [
            ['Neraca (Balance Sheet)'],
            ['Per Tanggal', $r['as_of']->format('d M Y')],
            ['Toko', $r['store_label'] ?? 'Semua Toko'],
        ];

        if ($this->hasCompare) {
            $rows[] = ['Pembanding', $r['compare_label'] ?? ''];
        }

        $rows[] = [];

        $this->sectionRowIndexes = [];
        $this->totalRowIndexes = [];
        $this->headerRowIndexes = [];

        $this->headerRowIndexes[] = count($rows) + 1;
        $rows[] = $this->hasCompare
            ? ['Akun', 'Saldo', '% Total Aset', 'Pembanding', 'Selisih %']
            : ['Akun', 'Saldo', '% Total Aset'];

        $addGroup = function (string $key, string $title) use (&$rows, $r) {
            $this->sectionRowIndexes[$key] = count($rows) + 1;
            $rows[] = [strtoupper($title)];

            $groups = $r[$key]['rows']->groupBy(fn ($row) => $row['account']->parent?->name ?? '');
            $showGroups = $groups->count() > 1 || ($groups->keys()->first() ?? '') !== '';

            foreach ($groups as $groupName => $items) {
                if ($showGroups && $groupName !== '') {
                    $rows[] = ['  ' . $groupName];
                }

                foreach ($items as $row) {
                    $name = '    ' . $row['account']->name . ($row['account']->is_contra ? ' (pengurang)' : '');
                    $prev = $this->prevMap !== null ? (float) ($this->prevMap[$row['account']->id] ?? 0) : null;
                    $rows[] = $this->line($name, (float) $row['balance'], $prev);
                }

                if ($showGroups && $groupName !== '' && $items->count() > 1) {
                    $rows[] = $this->line('  Subtotal ' . $groupName, (float) $items->sum('balance'));
                }
            }
        };

        // ASET
        $addGroup('aset', 'Aset');
        $this->totalRowIndexes[] = count($rows) + 1;
        $rows[] = $this->line('Total Aset', (float) $r['aset']['total'], $compare ? (float) $compare['aset']['total'] : null);
        $rows[] = [];

        // KEWAJIBAN
        $addGroup('kewajiban', 'Kewajiban');
        $this->totalRowIndexes[] = count($rows) + 1;
        $rows[] = $this->line('Total Kewajiban', (float) $r['kewajiban']['total'], $compare ? (float) $compare['kewajiban']['total'] : null);
        $rows[] = [];

        // MODAL
        $addGroup('modal', 'Modal');
        if (abs((float) $r['modal']['laba_tahun_lalu']) >= 0.005) {
            $rows[] = $this->line('    Laba (Rugi) Tahun-Tahun Sebelumnya (belum ditutup)', (float) $r['modal']['laba_tahun_lalu']);
        }
        $rows[] = $this->line('    Laba (Rugi) Tahun Berjalan', (float) $r['modal']['laba_tahun_berjalan']);
        $this->totalRowIndexes[] = count($rows) + 1;
        $rows[] = $this->line('Total Modal', (float) $r['modal']['total'], $compare ? (float) $compare['modal']['total'] : null);
        $rows[] = [];

        $this->totalRowIndexes[] = count($rows) + 1;
        $rows[] = $this->line('Total Kewajiban + Modal', (float) $r['total_kewajiban_modal'], $compare ? (float) $compare['total_kewajiban_modal'] : null);
        $rows[] = [];
        $rows[] = [$r['is_balanced']
            ? 'Balance — Total Aset sama dengan Total Kewajiban + Modal.'
            : 'TIDAK balance — periksa jurnal yang mungkin belum lengkap.'];

        // Rasio keuangan dasar.
        if (! empty($r['ratios'])) {
            $x = $r['ratios'];
            $fmt = fn ($v, string $suffix = '') => $v === null ? '-' : $v . $suffix;

            $rows[] = [];
            $rows[] = ['Rasio Keuangan'];
            $rows[] = ['Rasio Lancar (Aset Lancar / Kewajiban Lancar)', $fmt($x['current_ratio'], 'x')];
            $rows[] = ['Modal Kerja (Aset Lancar - Kewajiban Lancar)', (float) $x['working_capital']];
            $rows[] = ['Kewajiban terhadap Modal', $fmt($x['debt_to_equity'], 'x')];
            $rows[] = ['Kewajiban terhadap Aset', $fmt($x['debt_to_assets'], '%')];
        }

        return $rows;
    }

    public function columnWidths(): array
    {
        return $this->hasCompare
            ? ['A' => 52, 'B' => 18, 'C' => 15, 'D' => 18, 'E' => 12]
            : ['A' => 52, 'B' => 18, 'C' => 15];
    }

    public function styles(Worksheet $sheet): array
    {
        // array() dipanggil dulu oleh package Excel sebelum styles(),
        // jadi indeks baris di atas sudah terisi pada titik ini.
        $last = $this->hasCompare ? 'E' : 'C';

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
            $styles["A{$rowNumber}:{$last}{$rowNumber}"] = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']]];
        }

        foreach ($this->totalRowIndexes as $rowNumber) {
            $styles[$rowNumber] = ['font' => ['bold' => true]];
        }

        return $styles;
    }
}

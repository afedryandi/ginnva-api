<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
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
 */
class BalanceSheetExport implements FromArray, WithStyles
{
    public function __construct(private array $result) {}

    public function array(): array
    {
        $r = $this->result;

        $rows = [
            ['Neraca (Balance Sheet)'],
            ['Per Tanggal', $r['as_of']->format('d M Y')],
            [],
        ];

        $this->sectionRowIndexes = [];

        // ASET
        $this->sectionRowIndexes['aset'] = count($rows) + 1;
        $rows[] = ['ASET'];
        foreach ($r['aset']['rows'] as $row) {
            $rows[] = ['    ' . $row['account']->name, (float) $row['balance']];
        }
        $this->totalRowIndexes[] = count($rows) + 1;
        $rows[] = ['Total Aset', (float) $r['aset']['total']];
        $rows[] = [];

        // KEWAJIBAN
        $this->sectionRowIndexes['kewajiban'] = count($rows) + 1;
        $rows[] = ['KEWAJIBAN'];
        foreach ($r['kewajiban']['rows'] as $row) {
            $rows[] = ['    ' . $row['account']->name, (float) $row['balance']];
        }
        $this->totalRowIndexes[] = count($rows) + 1;
        $rows[] = ['Total Kewajiban', (float) $r['kewajiban']['total']];
        $rows[] = [];

        // MODAL
        $this->sectionRowIndexes['modal'] = count($rows) + 1;
        $rows[] = ['MODAL'];
        foreach ($r['modal']['rows'] as $row) {
            $rows[] = ['    ' . $row['account']->name, (float) $row['balance']];
        }
        $rows[] = ['    Laba (Rugi) Tahun Berjalan', (float) $r['modal']['laba_tahun_berjalan']];
        $this->totalRowIndexes[] = count($rows) + 1;
        $rows[] = ['Total Modal', (float) $r['modal']['total']];
        $rows[] = [];

        $this->totalRowIndexes[] = count($rows) + 1;
        $rows[] = ['Total Kewajiban + Modal', (float) $r['total_kewajiban_modal']];
        $rows[] = [];
        $rows[] = [$r['is_balanced']
            ? 'Balance — Total Aset sama dengan Total Kewajiban + Modal.'
            : 'TIDAK balance — periksa jurnal yang mungkin belum lengkap.'];

        return $rows;
    }

    /** @var array<string,int> */
    private array $sectionRowIndexes = [];

    /** @var int[] */
    private array $totalRowIndexes = [];

    public function styles(Worksheet $sheet): array
    {
        // array() dipanggil dulu oleh package Excel sebelum styles(),
        // jadi $sectionRowIndexes/$totalRowIndexes di atas sudah terisi
        // pada titik ini.
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
        ];

        foreach ($this->sectionRowIndexes as $rowNumber) {
            $styles[$rowNumber] = ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]];
            $styles["A{$rowNumber}:B{$rowNumber}"] = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']]];
        }

        foreach ($this->totalRowIndexes as $rowNumber) {
            $styles[$rowNumber] = ['font' => ['bold' => true]];
        }

        return $styles;
    }
}

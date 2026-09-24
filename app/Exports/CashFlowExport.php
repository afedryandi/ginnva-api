<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Arus Kas" (CashFlowReport) ke Excel. FromArray karena
 * hasilnya array bersarang (sections -> rows) dari
 * FinancialStatementService::cashFlowStatement(), bukan Eloquent Builder.
 * Baris per-transaksi diberi indentasi (spasi di depan deskripsi) supaya
 * pengelompokan Operasional/Investasi/Pendanaan tetap terlihat sama
 * seperti tampilan layar, bukan diratakan jadi daftar datar.
 */
class CashFlowExport implements FromArray, WithStyles
{
    /** Nomor baris section (di-bold+fill) — dikumpulkan saat array() dibangun, dipakai lagi di styles(). */
    private array $sectionRows = [];

    public function __construct(private array $result) {}

    public function array(): array
    {
        $r = $this->result;
        $rupiah = fn ($n) => number_format($n, 0, ',', '.');
        $rows = [];

        $rows[] = ['Laporan Arus Kas'];
        $rows[] = ['Periode', $r['from']->format('d M Y') . ' - ' . $r['to']->format('d M Y')];
        $rows[] = [];
        $rows[] = ['Saldo Kas Awal Periode', $rupiah($r['opening_cash'])];
        $rows[] = [];

        foreach ($r['sections'] as $section) {
            $this->sectionRows[] = count($rows) + 1;
            $rows[] = [$section['label']];

            foreach ($section['rows'] as $row) {
                $rows[] = [
                    '    ' . $row['entry_date']->format('d M Y') . ' — ' . $row['description'] . ' (' . $row['entry_number'] . ')',
                    $rupiah($row['amount']),
                ];
            }

            $rows[] = ['Total ' . $section['label'], $rupiah($section['total'])];
            $rows[] = [];
        }

        $rows[] = ['Kenaikan (Penurunan) Kas Bersih', $rupiah($r['net_change'])];
        $rows[] = ['Saldo Kas Akhir Periode', $rupiah($r['closing_cash'])];
        $rows[] = [];
        $rows[] = [
            $r['is_reconciled']
                ? 'Sudah sesuai dengan saldo aktual akun kas (' . $rupiah($r['closing_cash_actual']) . ').'
                : 'Berbeda dari saldo aktual akun kas (' . $rupiah($r['closing_cash_actual']) . ') — periksa jurnal.',
        ];

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            4 => ['font' => ['bold' => true]],
        ];

        foreach ($this->sectionRows as $rowNumber) {
            $styles[$rowNumber] = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
            ];
        }

        return $styles;
    }
}

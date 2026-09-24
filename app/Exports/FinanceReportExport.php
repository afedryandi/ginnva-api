<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Keuangan" (FinanceReport) ke Excel. Sama pola dengan
 * SalesSummaryExport -- FromArray karena sumbernya bukan Eloquent Builder
 * biasa, melainkan 2 hasil agregasi (totals + breakdown per kategori)
 * dari FinanceTransaction::totalsForMonth()/byCategoryForMonth(), plus
 * saldo akun COA yang dipin (opsional, cuma full-access).
 *
 * @param array{month: \Illuminate\Support\Carbon, totals: array{in: float, out: float, net: float}, income: \Illuminate\Support\Collection, expense: \Illuminate\Support\Collection, pinnedAccounts: \Illuminate\Support\Collection} $result
 */
class FinanceReportExport implements FromArray, WithStyles
{
    public function __construct(private array $result) {}

    public function array(): array
    {
        $r = $this->result;
        $rupiah = fn ($n) => number_format((float) $n, 0, ',', '.');

        $rows = [
            ['Laporan Keuangan'],
            ['Bulan', $r['month']->translatedFormat('F Y')],
            [],
            ['RINGKASAN'],
            ['Total Pemasukan', $rupiah($r['totals']['in'])],
            ['Total Pengeluaran', $rupiah($r['totals']['out'])],
            ['Saldo Bersih', $rupiah($r['totals']['net'])],
            [],
        ];

        $rows[] = ['RINCIAN PEMASUKAN PER KATEGORI'];
        if ($r['income']->isEmpty()) {
            $rows[] = ['Belum ada transaksi pemasukan di periode ini.'];
        } else {
            foreach ($r['income'] as $row) {
                $rows[] = [$row['category'], $rupiah($row['total'])];
            }
        }
        $rows[] = [];

        $rows[] = ['RINCIAN PENGELUARAN PER KATEGORI'];
        if ($r['expense']->isEmpty()) {
            $rows[] = ['Belum ada transaksi pengeluaran di periode ini.'];
        } else {
            foreach ($r['expense'] as $row) {
                $rows[] = [$row['category'], $rupiah($row['total'])];
            }
        }

        if ($r['pinnedAccounts']->isNotEmpty()) {
            $rows[] = [];
            $rows[] = ['SALDO AKUN PILIHAN'];
            foreach ($r['pinnedAccounts'] as $row) {
                $rows[] = [$row['account']->display_name, $rupiah($row['balance'])];
            }
        }

        return $rows;
    }

    /**
     * Section header baris DICARI DINAMIS (bukan hardcode nomor baris
     * seperti SalesSummaryExport) -- laporan ini punya jumlah baris
     * variabel (kategori & akun pilihan beda-beda tiap bulan/toko), beda
     * dari SalesSummaryReport yang selalu punya struktur baris tetap.
     */
    public function styles(Worksheet $sheet): array
    {
        $styles = [1 => ['font' => ['bold' => true, 'size' => 14]]];

        foreach ($this->array() as $i => $row) {
            $rowNum = $i + 1;
            if (isset($row[0]) && $row[0] === strtoupper($row[0]) && ! isset($row[1]) && $row[0] !== 'Laporan Keuangan') {
                $styles["A{$rowNum}"] = ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]];
                $styles["A{$rowNum}:B{$rowNum}"] = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '374151']]];
            }
        }

        return $styles;
    }
}

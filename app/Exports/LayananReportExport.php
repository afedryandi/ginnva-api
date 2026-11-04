<?php

namespace App\Exports;

use App\Filament\Pages\LayananReport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Jasa" / "Laporan Jenis Order" (LayananReport / JenisOrderReport — logic sama, cuma nama menu beda). $title dipakai
 * supaya file export dari halaman "Laporan Jenis Order" tidak keliru bertuliskan "Laporan Jasa".
 *
 * Susunan lembar dibangun SEKALI di layout() (baris + nomor baris tiap blok), lalu dipakai array() dan styles(), jadi format sel tidak
 * lagi bergantung pada nomor baris yang ditulis tangan.
 */
class LayananReportExport implements FromArray, WithStyles
{
    public function __construct(private array $result, private string $title = 'Laporan Jasa') {}

    public function array(): array
    {
        return $this->layout()['rows'];
    }

    /**
     * @return array{rows: array<int, array<int, mixed>>, money: list<string>, pct: list<string>}
     *         money/pct = rentang sel (mis. "D13:D18") yang diberi format rupiah / persen
     */
    private function layout(): array
    {
        $r = $this->result;
        // Semua nominal ditulis sebagai ANGKA (bukan teks berformat) supaya bisa dijumlahkan/difilter di Excel; tampilan diatur lewat
        // format sel di styles(). Pengembalian bernilai negatif.
        $number = fn ($n) => round((float) $n, 2);
        $labels = LayananReport::TYPE_LABELS;
        $typeKeys = array_values(array_filter(array_keys($labels), fn ($key) => $key !== 'lainnya' || $r['byType']['lainnya']['count'] > 0));
        $storeTypeKeys = array_values(array_filter($typeKeys, fn ($key) => $key !== 'lainnya' || array_sum(array_column(array_column($r['byStore'], 'types'), 'lainnya')) > 0));

        $rows = [
            [$this->title],
            ['Periode', $r['from']->format('d M Y') . ' - ' . $r['to']->format('d M Y')],
            [],
            ['RINGKASAN'],
            ['Jumlah Transaksi', $r['totalCount']],
            ['Total Pendapatan (bersih)', $number($r['totalRevenue'])],
            ['Pengembalian', (float) $r['refund'] > 0 ? -$number($r['refund']) : 0.0],
            ['Total Pendapatan (kotor)', $number($r['grossRevenue'])],
            ['Rata-rata per Transaksi', $number($r['avgRevenue'])],
            [],
        ];
        $money = ['B6:B9'];
        $pct = [];

        // ---- Per jenis layanan
        $rows[] = ['PER JENIS LAYANAN (pendapatan kotor; Pengembalian dan Bersih dibagi dengan proporsi yang sama)'];
        $rows[] = ['Jenis', 'Jumlah', 'Jumlah %', 'Pendapatan', 'Pendapatan %', 'Rata-rata', 'Pengembalian', 'Bersih'];
        $first = count($rows) + 1;
        foreach ($typeKeys as $key) {
            $row = $r['byType'][$key];
            $rows[] = [$labels[$key], $row['count'], round($row['countPct'], 1), $number($row['revenue']), round($row['revenuePct'], 1), $number($row['avg']), $row['refund'] > 0 ? -$number($row['refund']) : 0.0, $number($row['net'])];
        }
        $rows[] = ['Total', $r['slotCount'], '', $number($r['grossRevenue']), '', '', (float) $r['refund'] > 0 ? -$number($r['refund']) : 0.0, $number($r['totalRevenue'])];
        $last = count($rows);
        $pct[] = "C{$first}:C{$last}";
        $pct[] = "E{$first}:E{$last}";
        $money[] = "D{$first}:D{$last}";
        $money[] = "F{$first}:H{$last}";
        $typeTotalRow = $last;
        $rows[] = [];

        // ---- Per toko
        $rows[] = ['PER TOKO (pendapatan kotor menurut jenis layanan)'];
        $rows[] = array_merge(['Toko', 'Jumlah'], array_map(fn ($key) => $labels[$key], $storeTypeKeys), ['Pendapatan', 'Pengembalian', 'Bersih']);
        $storeFirst = count($rows) + 1;
        foreach ($r['byStore'] as $storeName => $row) {
            $rows[] = array_merge(
                [$storeName, $row['count']],
                array_map(fn ($key) => $number($row['types'][$key]), $storeTypeKeys),
                [$number($row['revenue']), $row['refund'] > 0 ? -$number($row['refund']) : 0.0, $number($row['net'])]
            );
        }

        if (count($r['byStore']) > 0) {
            $rows[] = array_merge(
                ['Total', $r['totalCount']],
                array_map(fn ($key) => $number(array_sum(array_column(array_column($r['byStore'], 'types'), $key))), $storeTypeKeys),
                [$number($r['grossRevenue']), (float) $r['refund'] > 0 ? -$number($r['refund']) : 0.0, $number($r['totalRevenue'])]
            );
            $lastColumn = chr(ord('A') + 1 + count($storeTypeKeys) + 3); // Toko, Jumlah, jenis…, Pendapatan, Pengembalian, Bersih
            $money[] = 'C' . $storeFirst . ':' . $lastColumn . count($rows);
        }

        return ['rows' => $rows, 'money' => $money, 'pct' => $pct, 'typeTotalRow' => $typeTotalRow, 'storeTotalRow' => count($r['byStore']) > 0 ? count($rows) : null];
    }

    public function styles(Worksheet $sheet): array
    {
        $layout = $this->layout();
        $format = fn (string $code) => ['numberFormat' => ['formatCode' => $code]];

        $styles = [1 => ['font' => ['bold' => true, 'size' => 14]]];

        foreach ($layout['money'] as $range) {
            $styles[$range] = $format('#,##0;(#,##0);"-"');
        }
        foreach ($layout['pct'] as $range) {
            $styles[$range] = $format('0.0');
        }

        foreach (array_filter([$layout['typeTotalRow'], $layout['storeTotalRow']]) as $row) {
            $styles[$row] = ['font' => ['bold' => true]];
        }

        return $styles;
    }
}

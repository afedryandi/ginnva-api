<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Jasa" / "Laporan Jenis Order" (LayananReport /
 * JenisOrderReport — logic sama, cuma nama menu beda) — audit
 * 2026-09-11, temuan B. $title dipakai supaya file export dari halaman
 * "Laporan Jenis Order" tidak keliru bertuliskan "Laporan Jasa".
 */
class LayananReportExport implements FromArray, WithStyles
{
    public function __construct(private array $result, private string $title = 'Laporan Jasa') {}

    public function array(): array
    {
        $r = $this->result;
        $rupiah = fn ($n) => number_format((float) $n, 0, ',', '.');

        $rows = [
            [$this->title],
            ['Periode', $r['from']->format('d M Y') . ' - ' . $r['to']->format('d M Y')],
            [],
            ['RINGKASAN'],
            ['Transaksi', $r['totalCount']],
            ['Total Pendapatan (bersih)', $rupiah($r['totalRevenue'])],
            ['Pengembalian', $r['refund'] > 0 ? '(' . $rupiah($r['refund']) . ')' : '-'],
            ['Total Pendapatan (kotor)', $rupiah($r['grossRevenue'])],
            ['Rata-rata per Jasa', $rupiah($r['avgRevenue'])],
            [],
            ['PER JENIS SERVIS (kotor, belum dikurangi pengembalian)'],
            ['Jenis', 'Jumlah', 'Jumlah %', 'Pendapatan', 'Pendapatan %'],
            ['Kaca Film', $r['byType']['kaca_film']['count'], round($r['byType']['kaca_film']['countPct'], 1) . '%', $rupiah($r['byType']['kaca_film']['revenue']), round($r['byType']['kaca_film']['revenuePct'], 1) . '%'],
            ['PPF', $r['byType']['ppf']['count'], round($r['byType']['ppf']['countPct'], 1) . '%', $rupiah($r['byType']['ppf']['revenue']), round($r['byType']['ppf']['revenuePct'], 1) . '%'],
            [],
            ['PER TOKO'],
            ['Toko', 'Jumlah', 'Pendapatan'],
        ];

        foreach ($r['byStore'] as $storeName => $row) {
            $rows[] = [$storeName, $row['count'], $rupiah($row['revenue'])];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
        ];
    }
}

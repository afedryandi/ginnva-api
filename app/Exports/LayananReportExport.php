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
        // Semua nominal ditulis sebagai ANGKA (bukan teks berformat) supaya bisa dijumlahkan/difilter di Excel;
        // tampilan ribuan/kurung/persen diatur lewat format sel di styles(). Pengembalian bernilai negatif.
        $number = fn ($n) => round((float) $n, 2);

        $rows = [
            [$this->title],
            ['Periode', $r['from']->format('d M Y') . ' - ' . $r['to']->format('d M Y')],
            [],
            ['RINGKASAN'],
            ['Transaksi', $r['totalCount']],
            ['Total Pendapatan (bersih)', $number($r['totalRevenue'])],
            ['Pengembalian', (float) $r['refund'] > 0 ? -$number($r['refund']) : 0.0],
            ['Total Pendapatan (kotor)', $number($r['grossRevenue'])],
            ['Rata-rata per Jasa', $number($r['avgRevenue'])],
            [],
            ['PER JENIS SERVIS (kotor, belum dikurangi pengembalian)'],
            ['Jenis', 'Jumlah', 'Jumlah %', 'Pendapatan', 'Pendapatan %'],
            ['Kaca Film', $r['byType']['kaca_film']['count'], round($r['byType']['kaca_film']['countPct'], 1), $number($r['byType']['kaca_film']['revenue']), round($r['byType']['kaca_film']['revenuePct'], 1)],
            ['PPF', $r['byType']['ppf']['count'], round($r['byType']['ppf']['countPct'], 1), $number($r['byType']['ppf']['revenue']), round($r['byType']['ppf']['revenuePct'], 1)],
            ['Detailing', $r['byType']['detailing']['count'], round($r['byType']['detailing']['countPct'], 1), $number($r['byType']['detailing']['revenue']), round($r['byType']['detailing']['revenuePct'], 1)],
            ['Premium Wash', $r['byType']['premium_wash']['count'], round($r['byType']['premium_wash']['countPct'], 1), $number($r['byType']['premium_wash']['revenue']), round($r['byType']['premium_wash']['revenuePct'], 1)],
            ['Lainnya (tanpa jenis)', $r['byType']['lainnya']['count'], round($r['byType']['lainnya']['countPct'], 1), $number($r['byType']['lainnya']['revenue']), round($r['byType']['lainnya']['revenuePct'], 1)],
            [],
            ['PER TOKO'],
            ['Toko', 'Jumlah', 'Pendapatan'],
        ];

        foreach ($r['byStore'] as $storeName => $row) {
            $rows[] = [$storeName, $row['count'], $number($row['revenue'])];
        }

        return $rows;
    }

    /**
     * Nomor baris HARDCODED mengikuti urutan array(): ringkasan nominal di B6:B9, tabel jenis di baris 13-17
     * (C & E persen, D rupiah), tabel toko mulai baris 21 (C rupiah). Ubah array() => sesuaikan di sini.
     */
    public function styles(Worksheet $sheet): array
    {
        $money = '#,##0;(#,##0);"-"';
        $pct = '0.0';
        $format = fn (string $code) => ['numberFormat' => ['formatCode' => $code]];

        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            'B6:B9' => $format($money),
            'C13:C17' => $format($pct),
            'D13:D17' => $format($money),
            'E13:E17' => $format($pct),
        ];

        $storeCount = count($this->result['byStore']);
        if ($storeCount > 0) {
            $styles['C21:C' . (20 + $storeCount)] = $format($money);
        }

        return $styles;
    }
}

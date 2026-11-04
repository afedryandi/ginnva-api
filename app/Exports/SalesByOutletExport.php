<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Penjualan Outlet" (SalesByOutletReport) — audit 2026-09-11,
 * temuan B. FromArray karena sumbernya hasil agregasi getResult(), pola
 * sama SalesSummaryExport/SalesByPeriodExport.
 *
 * 2026-11-04: kolom Piutang hanya ada bila modul Keuangan menyala (saat mati, piutang dicatat di Accurate), dan ada baris Total di akhir.
 */
class SalesByOutletExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        $headings = [
            'Outlet',
            'Transaksi',
            'Penjualan (bersih)',
            'Penjualan %',
            'Pengembalian',
            'Produk',
            'Produk %',
            'Rata-rata/Transaksi',
            'Produk/Transaksi',
        ];

        if (config('features.finance')) {
            $headings[] = 'Piutang';
        }

        return $headings;
    }

    public function array(): array
    {
        $finance = (bool) config('features.finance');

        $rows = collect($this->result['rows'])
            ->map(function (array $row) use ($finance) {
                $line = [
                    $row['store']->name,
                    $row['count'],
                    $row['revenue'],
                    round($row['revenuePct'], 1), // angka (kolom berjudul %), bukan teks, supaya bisa dijumlah/difilter
                    $row['refund'],
                    $row['products'],
                    round($row['productsPct'], 1),
                    round($row['avg']),
                    round($row['productsPerTransaction'], 2),
                ];

                if ($finance) {
                    $line[] = $row['outstanding'];
                }

                return $line;
            })
            ->values()
            ->all();

        $count = $this->result['totalCount'];
        $total = [
            'Total',
            $count,
            $this->result['totalRevenue'],
            $this->result['totalRevenue'] > 0 ? 100.0 : 0.0,
            $this->result['totalRefund'],
            $this->result['totalProducts'],
            $this->result['totalProducts'] > 0 ? 100.0 : 0.0,
            $count > 0 ? round($this->result['totalRevenue'] / $count) : 0,
            $count > 0 ? round($this->result['totalProducts'] / $count, 2) : 0,
        ];

        if ($finance) {
            $total[] = $this->result['totalOutstanding'];
        }

        $rows[] = $total;

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1F2937'],
                ],
            ],
        ];
    }
}

<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Penjualan Produk" (ProductSalesReport): satu baris per SKU, baris Total di ujung. Angka tetap numerik; hanya komisi yang
 * belum lengkap (installer tanpa tarif) berubah jadi teks bertanda " *".
 */
class ProductSalesReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Produk',
            'SKU',
            'Jenis Produk',
            'Jumlah Transaksi',
            'Jumlah %',
            'Penjualan',
            'Penjualan %',
            'Jumlah Refund',
            'Refund',
            'Komisi',
        ];
    }

    public function array(): array
    {
        $rows = collect($this->result['rows'])
            ->map(fn (array $row) => [
                $row['name'],
                $row['sku'],
                $row['type'],
                $row['count'],
                // Persentase berupa angka (judul kolom sudah memuat %), bukan teks, supaya bisa dijumlah/difilter.
                round($row['countPct'], 1),
                $row['revenue'],
                round($row['revenuePct'], 1),
                $row['refundCount'],
                $row['refundAmount'],
                $row['hasUnratedJob'] ? $row['commission'] . ' *' : $row['commission'],
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            $rows[] = ['Total', '', '', (int) $this->result['slotCount'], '', (float) $this->result['grossRevenue'], '', '', (float) $this->result['totalRefundAmount'], (float) $this->result['totalCommission']];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1F2937'],
                ],
            ],
        ];

        if (count($this->result['rows']) > 0) {
            $styles[count($this->result['rows']) + 2] = ['font' => ['bold' => true]];
        }

        return $styles;
    }
}

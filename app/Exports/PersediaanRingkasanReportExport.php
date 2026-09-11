<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Lap. Ringkasan Persediaan" (PersediaanRingkasanReport) —
 * audit 2026-09-11, temuan B. Snapshot kondisi TERKINI (bukan per
 * tanggal) — konsisten dengan halaman sumbernya, tidak ada filter.
 */
class PersediaanRingkasanReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Nama Produk',
            'SKU',
            'Jenis',
            'Kategori',
            'Kuantitas',
            'Satuan',
            'Harga Modal',
            'Total Nilai Persediaan',
        ];
    }

    public function array(): array
    {
        return collect($this->result['rows'])
            ->map(fn (array $row) => [
                $row['name'],
                $row['sku'],
                $row['type'],
                $row['category'],
                $row['quantity'],
                $row['unit'],
                $row['unitCost'],
                $row['totalValue'],
            ])
            ->values()
            ->all();
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

<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Daftar Stok" (StockCardReport) — audit 2026-09-14, temuan B. Audit 2026-11-03: judul dan periode di baris atas,
 * kolom Kategori, Status, Stok Minimum, dan catatan bila Awal/Akhir tidak sinkron.
 */
class StockCardReportExport implements FromArray, WithHeadings, WithStyles
{
    private const HEADER_ROW = 4;

    public function __construct(private array $result) {}

    public function headings(): array
    {
        $jenis = match ($this->result['jenis'] ?? 'all') {
            'raw_material' => 'Bahan Baku',
            'consumable_item' => 'Barang Habis Pakai',
            default => 'Semua jenis',
        };

        return [
            ['Daftar Stok (Kartu Stok)'],
            ['Periode: ' . $this->result['from']->format('d/m/Y') . ' s/d ' . $this->result['to']->format('d/m/Y') . ' · ' . $jenis],
            [],
            ['Kode', 'Nama', 'Jenis', 'Kategori', 'Status', 'Awal', 'Masuk', 'Keluar', 'Akhir', 'Satuan', 'Stok Minimum', 'Catatan'],
        ];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->result['rows'] as $row) {
            $rows[] = [
                $row['code'] ?: '-',
                $row['name'],
                $row['jenis'],
                $row['category'] ?: '-',
                $row['inactive'] ? 'Nonaktif' : 'Aktif',
                $row['awal'],
                $row['masuk'],
                $row['keluar'],
                $row['akhir'],
                $row['unit'],
                $row['reorder_point'] ?? '-',
                $row['anomaly'] ? 'Stok dan riwayat tidak sinkron' : ($row['low'] ? 'Stok menipis' : ''),
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            self::HEADER_ROW => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2937']],
            ],
        ];
    }
}
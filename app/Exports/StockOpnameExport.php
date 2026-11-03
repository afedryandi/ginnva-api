<?php

namespace App\Exports;

use App\Models\StockOpname;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Berita acara satu sesi Stok Opname: judul, status, lalu tiap item dengan stok sistem (snapshot), hasil hitung, selisih, dan catatan. */
class StockOpnameExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    public function __construct(private StockOpname $opname)
    {
    }

    public function headings(): array
    {
        return [
            ["Berita Acara Stok Opname {$this->opname->opname_number}"],
            ['Toko: ' . ($this->opname->store?->name ?? '-') . ' · Tanggal: ' . $this->opname->opname_date->format('d/m/Y') . ' · Status: ' . (StockOpname::STATUS_LABELS[$this->opname->status] ?? $this->opname->status)],
            [],
            ['Item', 'Jenis', 'Stok Sistem (saat dihitung)', 'Hasil Hitung', 'Selisih', 'Satuan', 'Catatan'],
        ];
    }

    public function array(): array
    {
        return $this->opname->items->map(fn ($i) => [
            $i->item_name,
            $i->item_type === 'raw_material' ? 'Bahan Baku' : 'Barang Habis Pakai',
            (float) $i->system_quantity,
            (float) $i->actual_quantity,
            (float) $i->delta,
            $i->unit,
            $i->note ?: '',
        ])->all();
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            4 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2937']],
            ],
        ];
    }
}
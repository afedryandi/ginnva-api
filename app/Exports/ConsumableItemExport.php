<?php

namespace App\Exports;

use App\Models\ConsumableItem;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Barang Habis Pakai" (ConsumableItemResource) — audit
 * 2026-09-12, temuan pola standar. Sama pola RawMaterialExport
 * (resource ini sengaja mirror RawMaterialResource).
 */
class ConsumableItemExport implements FromCollection, WithHeadings, WithStyles
{
    public function headings(): array
    {
        return ['Kode', 'Nama Barang', 'Kategori', 'Stok', 'Satuan', 'Ambang Menipis', 'Harga/Satuan'];
    }

    public function collection(): Collection
    {
        return ConsumableItem::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ConsumableItem $item) => [
                $item->code ?? '-',
                $item->name,
                $item->category ?? '-',
                (float) $item->current_stock,
                $item->unit,
                $item->reorder_point !== null ? (float) $item->reorder_point : '-',
                $item->unit_cost !== null ? (float) $item->unit_cost : '-',
            ]);
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2937']],
            ],
        ];
    }
}

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
    /** Kolom harga hanya untuk yang boleh melihat harga (ConsumableItemResource::canSeeCost()). */
    /** $query: daftar yang sedang difilter/dicari di layar; kosong = semua barang menurut abjad. */
    public function __construct(private bool $withCost = true, private ?\Illuminate\Database\Eloquent\Builder $query = null)
    {
    }

    public function headings(): array
    {
        $headings = ['Kode', 'Nama Barang', 'Kategori', 'Stok', 'Satuan', 'Ambang Menipis', 'Harga/Satuan'];

        return $this->withCost ? $headings : array_values(array_diff_key($headings, [6 => true]));
    }

    public function collection(): Collection
    {
        return ($this->query ? $this->query->get() : ConsumableItem::query()->orderBy('name')->get())
            ->map(fn (ConsumableItem $item) => $this->withoutCostColumn([
                $item->code ?? '-',
                $item->name,
                $item->category ?? '-',
                (float) $item->current_stock,
                $item->unit,
                $item->reorder_point !== null ? (float) $item->reorder_point : '-',
                $item->unit_cost !== null ? (float) $item->unit_cost : '-',
            ]));
    }

    private function withoutCostColumn(array $row): array
    {
        return $this->withCost ? $row : array_values(array_diff_key($row, [6 => true]));
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

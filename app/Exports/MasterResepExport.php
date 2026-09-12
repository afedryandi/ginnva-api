<?php

namespace App\Exports;

use App\Models\FilmProduct;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Master Resep" (MasterResepResource) — audit 2026-09-12,
 * temuan pola standar. 1 baris per bahan (produk tanpa resep tetap
 * muncul 1 baris "Belum diisi").
 */
class MasterResepExport implements FromCollection, WithHeadings, WithStyles
{
    private const TYPE_LABEL = [
        'window_film' => 'Kaca Film',
        'ppf' => 'PPF',
        'detailing' => 'Detailing',
        'color_change' => 'Ganti Warna',
    ];

    private const ITEM_TYPE_LABEL = [
        'raw_material' => 'Bahan Baku',
        'consumable_item' => 'Barang Habis Pakai',
        'film_roll' => 'Roll Film (meteran)',
    ];

    public function headings(): array
    {
        return ['SKU', 'Nama Produk', 'Tipe Produk', 'Jenis Bahan', 'Nama Bahan', 'Jumlah', 'Satuan', 'Catatan'];
    }

    public function collection(): Collection
    {
        $rows = collect();

        FilmProduct::query()
            ->with('recipeItems')
            ->orderBy('name')
            ->get()
            ->each(function (FilmProduct $product) use ($rows) {
                if ($product->recipeItems->isEmpty()) {
                    $rows->push([
                        $product->sku,
                        $product->name,
                        self::TYPE_LABEL[$product->product_type] ?? $product->product_type,
                        '-', 'Belum diisi', '-', '-', '-',
                    ]);

                    return;
                }

                foreach ($product->recipeItems as $item) {
                    $rows->push([
                        $product->sku,
                        $product->name,
                        self::TYPE_LABEL[$product->product_type] ?? $product->product_type,
                        self::ITEM_TYPE_LABEL[$item->item_type] ?? $item->item_type,
                        $item->item_name,
                        (float) $item->standard_qty,
                        $item->unit ?? '-',
                        $item->note ?: '-',
                    ]);
                }
            });

        return $rows;
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

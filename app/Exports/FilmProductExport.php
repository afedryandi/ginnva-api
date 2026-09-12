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
 * Export "Daftar Produk" (FilmProductResource) — audit 2026-09-12,
 * temuan pola standar B.
 */
class FilmProductExport implements FromCollection, WithHeadings, WithStyles
{
    private const TYPE_LABEL = [
        'window_film' => 'Kaca Film',
        'ppf' => 'PPF',
        'detailing' => 'Detailing',
        'color_change' => 'Ganti Warna',
    ];

    private const POSITION_LABEL = [
        'front' => 'Kaca Depan',
        'side_rear' => 'Samping & Belakang',
    ];

    public function headings(): array
    {
        return ['SKU', 'Nama Produk', 'Tipe', 'Posisi Kaca', 'Harga Dasar (Flat)', 'Harga per Ukuran', 'Aktif'];
    }

    public function collection(): Collection
    {
        return FilmProduct::query()
            ->with('prices')
            ->orderBy('name')
            ->get()
            ->map(function (FilmProduct $product) {
                $pricesText = $product->prices->isEmpty()
                    ? '-'
                    : $product->prices
                        ->map(fn ($p) => "{$p->vehicle_size}: Rp".number_format((float) $p->price, 0, ',', '.'))
                        ->implode('; ');

                return [
                    $product->sku,
                    $product->name,
                    self::TYPE_LABEL[$product->product_type] ?? $product->product_type,
                    $product->product_type === 'window_film' ? (self::POSITION_LABEL[$product->position] ?? '-') : '-',
                    (float) $product->base_price,
                    $pricesText,
                    $product->is_active ? 'Ya' : 'Tidak',
                ];
            });
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

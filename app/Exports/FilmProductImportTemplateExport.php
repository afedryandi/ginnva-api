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
 * Template "Import Harga Massal" (audit Majoo, f27) — BEDA dari
 * FilmProductExport (laporan lengkap read-only): file ini SENGAJA
 * pre-filled dengan katalog SAAT INI (bukan kosong) supaya staff
 * tinggal edit kolom Harga yang berubah lalu upload balik lewat
 * "Import Excel" — kolom yang tidak diubah boleh dibiarkan apa
 * adanya (tetap konsisten, tidak menimpa jadi kosong).
 */
class FilmProductImportTemplateExport implements FromCollection, WithHeadings, WithStyles
{
    public function headings(): array
    {
        return ['SKU', 'Nama Produk', 'Harga Dasar (Rp)', 'Aktif (Y/T)'];
    }

    public function collection(): Collection
    {
        return FilmProduct::query()
            ->orderBy('name')
            ->get()
            ->map(fn (FilmProduct $product) => [
                $product->sku,
                $product->name,
                (float) $product->base_price,
                $product->is_active ? 'Y' : 'T',
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

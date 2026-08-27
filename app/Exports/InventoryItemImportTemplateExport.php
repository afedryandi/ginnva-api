<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Template kosong untuk import massal di menu Produk PPF/WF — 2 baris
 * contoh (1 PPF, 1 Window Film) supaya format terlihat jelas tanpa perlu
 * baca helper text. Kolom mengikuti urutan yang dibaca
 * InventoryItemResource::importItems().
 */
class InventoryItemImportTemplateExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    public function array(): array
    {
        return [
            ['PPF Black Crystal M8M', 'PPF', 'M8M', '260518020101001', '14/08/2026', 'Contoh — hapus baris ini sebelum upload'],
            ['Window Film Bi-silver H70', 'Window Film', 'H70', '', '', 'Kode Gulungan boleh dikosongkan kalau belum tahu kodenya'],
        ];
    }

    public function headings(): array
    {
        return [
            'Nama Produk',
            'Kategori (PPF / Window Film)',
            'Kode Model Produk Film',
            'Kode Gulungan',
            'Tanggal Masuk',
            'Catatan',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
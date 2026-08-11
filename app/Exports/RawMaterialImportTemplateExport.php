<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RawMaterialImportTemplateExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    public function array(): array
    {
        return [
            ['Adhesive Premium', 'Adhesive', 'liter', 50, 10, 150000, '2027-06-30', 'Contoh — hapus baris ini sebelum upload'],
            ['Backing Paper', 'Packaging', 'meter', 200, 30, '', '', 'Tanggal Kedaluwarsa & Harga boleh dikosongkan'],
        ];
    }

    public function headings(): array
    {
        return [
            'Nama Bahan',
            'Kategori',
            'Satuan',
            'Stok Awal',
            'Ambang Stok Menipis',
            'Harga per Satuan',
            'Tanggal Kedaluwarsa (YYYY-MM-DD)',
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

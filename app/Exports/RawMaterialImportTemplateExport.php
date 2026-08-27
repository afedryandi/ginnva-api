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
            ['Adhesive Premium', 'ADH-001', 'Adhesive', 'liter', 50, 10, 150000, '14/08/2026', '30/06/2027', 'Contoh — hapus baris ini sebelum upload'],
            ['Backing Paper', '', 'Packaging', 'meter', 200, 30, '', '', '', 'Kode/Tanggal/Harga boleh dikosongkan'],
        ];
    }

    public function headings(): array
    {
        return [
            'Nama Bahan',
            'Kode Barang',
            'Kategori',
            'Satuan',
            'Stok Awal',
            'Ambang Stok Menipis',
            'Harga per Satuan',
            'Tanggal Masuk',
            'Tanggal Kedaluwarsa',
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
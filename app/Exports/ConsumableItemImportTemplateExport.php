<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ConsumableItemImportTemplateExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    public function array(): array
    {
        return [
            ['Lakban Kertas 2 Inch', 'LKB-001', 'Lakban', 'roll', 40, 10, 15000, 'Contoh — hapus baris ini sebelum upload'],
            ['Isi Cutter Besar', '', 'Alat Potong', 'pcs', 100, 20, '', 'Kode & Harga boleh dikosongkan'],
        ];
    }

    public function headings(): array
    {
        return [
            'Nama Barang',
            'Kode Barang',
            'Kategori',
            'Satuan',
            'Stok Awal',
            'Ambang Stok Menipis',
            'Harga per Satuan',
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

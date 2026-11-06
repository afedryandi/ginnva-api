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
            ['Sonax SX 90 Plus 400 ml', 'SX90-001', 'Pembersih', 'ml', 2400, 800, 60, '14/08/2026', '30/06/2027', 'Contoh — hapus baris ini sebelum upload. Stok 2400 ml = 6 botol; harga per ml', 'botol', 400],
            ['Backing Paper', '', 'Packaging', 'meter', 200, 30, '', '', '', 'Kode/Tanggal/Harga/Satuan Beli boleh dikosongkan', '', ''],
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
            'Satuan Beli',
            'Isi per Satuan Beli',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
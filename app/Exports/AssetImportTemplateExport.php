<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AssetImportTemplateExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    public function array(): array
    {
        return [
            ['Laptop Dell Latitude', 'Elektronik', 'aktif', 'Budi Santoso', 'Ginnva Kelapa Gading', '14/08/2026', '15/01/2025', 12000000, 'Contoh — hapus baris ini sebelum upload'],
            ['Mesin Cutting Plotter', 'Mesin', 'aktif', '', 'Ginnva Kelapa Gading', '', '', '', 'Dipegang Oleh & Tanggal/Harga boleh dikosongkan'],
        ];
    }

    public function headings(): array
    {
        return [
            'Nama Aset',
            'Kategori',
            'Status (aktif/diperbaiki/rusak/dijual/hilang)',
            'Dipegang Oleh (nama user, persis)',
            'Lokasi (nama toko, persis)',
            'Tanggal Masuk',
            'Tanggal Beli',
            'Harga Beli',
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

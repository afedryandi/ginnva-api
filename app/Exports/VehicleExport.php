<?php

namespace App\Exports;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Katalog kendaraan sebagai Excel. Format kolomnya SAMA dengan yang dibaca Import Kendaraan, jadi file ini sekaligus template:
 * export, koreksi di Excel, lalu import kembali (dengan opsi perbarui baris yang berbeda).
 */
class VehicleExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public const HEADINGS = ['Merek', 'Tipe/Model', 'Varian', 'Ukuran', 'Tipe Bodi', 'Tahun Dari', 'Tahun Sampai', 'Catatan', 'Aktif'];

    public function query(): Builder
    {
        return Vehicle::query()->orderBy('brand')->orderBy('model')->orderBy('variant');
    }

    public function headings(): array
    {
        return self::HEADINGS;
    }

    public function map($vehicle): array
    {
        return [
            $vehicle->brand,
            $vehicle->model,
            $vehicle->variant,
            $vehicle->size_category,
            $vehicle->body_type ? (Vehicle::BODY_TYPES[$vehicle->body_type] ?? $vehicle->body_type) : null,
            $vehicle->year_from,
            $vehicle->year_to,
            $vehicle->notes,
            $vehicle->is_active ? 'Ya' : 'Tidak',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}

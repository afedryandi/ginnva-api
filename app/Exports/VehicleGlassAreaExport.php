<?php

namespace App\Exports;

use App\Models\VehicleGlassArea;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class VehicleGlassAreaExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function query(): Builder
    {
        return VehicleGlassArea::query()->orderBy('brand')->orderBy('model');
    }

    public function headings(): array
    {
        return ['Merek', 'Tipe/Model', 'Depan (m²)', 'Samping (m²)', 'Belakang (m²)', 'Sunroof (m²)', 'Total (m²)', 'Di Katalog', 'Sumber'];
    }

    public function map($area): array
    {
        return [
            $area->brand,
            $area->model,
            (float) $area->front_sqm,
            (float) $area->side_sqm,
            (float) $area->rear_sqm,
            (float) $area->sunroof_sqm,
            $area->totalSqm(),
            $area->catalogVehicleCount() > 0 ? 'Ya' : 'Tidak',
            $area->source === 'import' ? 'Import' : 'Manual',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}

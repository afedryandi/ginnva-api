<?php

namespace App\Exports;

use App\Models\ScrollCode;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ScrollCodeExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function query(): Builder
    {
        return ScrollCode::with(['filmProduct', 'store'])->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'Kode', 'Produk Film', 'Toko', 'Status',
            'No. Garansi', 'Dialokasi Pada', 'Dipakai Pada', 'Dibuat Pada',
        ];
    }

    public function map($row): array
    {
        return [
            $row->code,
            $row->filmProduct?->name ?? '—',
            $row->store?->name ?? '—',
            match ($row->status) {
                'unallocated' => 'Belum Dialokasi',
                'allocated'   => 'Dialokasi',
                'used'        => 'Terpakai',
                default       => $row->status,
            },
            $row->warranty_code ?? '—',
            $row->allocated_at?->format('d/m/Y H:i') ?? '—',
            $row->used_at?->format('d/m/Y H:i') ?? '—',
            $row->created_at?->format('d/m/Y H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}

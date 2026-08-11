<?php

namespace App\Exports;

use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryMovementExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function query(): Builder
    {
        return InventoryMovement::with(['inventoryItem', 'user'])
            ->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'Waktu', 'Kode Barang', 'Nama Barang', 'Kategori',
            'Jenis', 'Dicatat Oleh', 'Catatan',
        ];
    }

    public function map($movement): array
    {
        return [
            $movement->created_at?->format('d/m/Y H:i'),
            $movement->inventoryItem?->code ?? '—',
            $movement->inventoryItem?->name ?? '— (barang sudah dihapus)',
            $movement->inventoryItem?->category ?? '—',
            $movement->type === 'in' ? 'Masuk' : 'Keluar',
            $movement->user?->name ?? '—',
            $movement->note,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}

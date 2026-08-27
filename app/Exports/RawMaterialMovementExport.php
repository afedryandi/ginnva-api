<?php

namespace App\Exports;

use App\Models\RawMaterialMovement;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sama pola dengan InventoryMovementExport (PPF/WF) — menerima query yang
 * sudah difilter dari tabel Filament yang sedang aktif (jenis, bahan,
 * dicatat oleh, rentang tanggal), bukan selalu dump semua baris.
 */
class RawMaterialMovementExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(private ?Builder $query = null)
    {
    }

    public function query(): Builder
    {
        return ($this->query ?? RawMaterialMovement::query())
            ->with(['rawMaterial', 'user'])
            ->reorder('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'Waktu', 'Bahan Baku', 'Kategori', 'Jenis',
            'Jumlah', 'Satuan', 'Harga/Satuan', 'Dicatat Oleh', 'Catatan',
        ];
    }

    public function map($movement): array
    {
        return [
            $movement->created_at?->format('d/m/Y H:i'),
            $movement->rawMaterial?->name ?? '— (bahan sudah dihapus)',
            $movement->rawMaterial?->category ?? '—',
            match ($movement->type) {
                'in' => 'Masuk',
                'out' => 'Keluar',
                'adjustment' => 'Penyesuaian (Opname)',
                default => $movement->type,
            },
            ($movement->quantity > 0 && $movement->type === 'adjustment' ? '+' : '') . number_format((float) $movement->quantity, 2),
            $movement->rawMaterial?->unit ?? '—',
            $movement->unit_cost !== null ? number_format((float) $movement->unit_cost, 2) : '—',
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

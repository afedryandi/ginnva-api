<?php

namespace App\Exports;

use App\Models\ConsumableItemMovement;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sama pola dengan RawMaterialMovementExport — menerima query yang sudah
 * difilter dari tabel Filament yang sedang aktif.
 */
class ConsumableItemMovementExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    /** Kolom harga hanya untuk yang boleh melihat harga (ConsumableItemMovementResource::canSeeCost()). */
    public function __construct(private ?Builder $query = null, private bool $withCost = true)
    {
    }

    public function query(): Builder
    {
        return ($this->query ?? ConsumableItemMovement::query()->visibleTo(auth()->user()))
            ->with(['consumableItem', 'user', 'store'])
            ->reorder('created_at', 'desc');
    }

    public function headings(): array
    {
        $headings = [
            'Waktu', 'Barang', 'Kategori', 'Jenis',
            'Jumlah', 'Satuan', 'Harga/Satuan', 'Dicatat Oleh', 'Catatan',
            'Toko', 'Sumber', 'Status',
        ];

        return $this->withCost ? $headings : array_values(array_diff_key($headings, [6 => true]));
    }

    public function map($movement): array
    {
        $row = [
            $movement->created_at?->format('d/m/Y H:i'),
            $movement->consumableItem?->name ?? '— (barang sudah dihapus)',
            $movement->consumableItem?->category ?? '—',
            match ($movement->type) {
                'in' => 'Masuk',
                'out' => 'Keluar',
                'adjustment' => 'Penyesuaian (Opname)',
                'correction' => 'Koreksi',
                default => $movement->type,
            },
            ($movement->quantity > 0 && $movement->type === 'adjustment' ? '+' : '') . number_format((float) $movement->quantity, 2),
            $movement->consumableItem?->unit ?? '—',
            $movement->unit_cost !== null ? number_format((float) $movement->unit_cost, 2) : '—',
            $movement->user?->name ?? '—',
            $movement->note,
            $movement->store?->name ?? '—',
            $movement->sourceText() ?? '—',
            $movement->reversed_at ? 'Dibatalkan' . ($movement->reversed_reason ? ': ' . $movement->reversed_reason : '') : 'Berlaku',
        ];

        return $this->withCost ? $row : array_values(array_diff_key($row, [6 => true]));
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}

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
    /**
     * Opsional — kalau diisi (dari filter tabel Filament yang sedang
     * aktif), export cuma ambil baris yang cocok filter itu. SEBELUMNYA
     * export selalu ambil SEMUA baris tanpa peduli filter yang admin
     * pilih di layar (jenis, dicatat oleh, kategori, rentang tanggal) —
     * admin yang filter "Keluar bulan ini" lalu export akan dapat dump
     * penuh, bukan laporan yang sedang mereka lihat.
     */
    public function __construct(private ?Builder $query = null)
    {
    }

    public function query(): Builder
    {
        return ($this->query ?? InventoryMovement::query())
            ->with(['inventoryItem', 'user', 'destinationStore'])
            ->reorder('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'Waktu', 'Kode Barang', 'Nama Barang', 'Kategori',
            'Jenis', 'Toko Tujuan', 'Dicatat Oleh', 'Catatan',
        ];
    }

    public function map($movement): array
    {
        return [
            $movement->created_at?->format('d/m/Y H:i'),
            $movement->inventoryItem?->code ?? '—',
            $movement->inventoryItem?->name ?? '— (barang sudah dihapus)',
            $movement->inventoryItem?->category ?? '—',
            match ($movement->type) {
                'in' => 'Masuk',
                'out' => 'Keluar',
                'correction' => 'Koreksi',
                default => $movement->type,
            },
            $movement->destinationStore?->name ?? '—',
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

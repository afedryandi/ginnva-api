<?php

namespace App\Exports;

use App\Models\StockWriteOff;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Stok Terbuang" (StockWriteOffResource) — audit 2026-09-14,
 * temuan pola standar. Sama pola InventoryMovementExport — kalau
 * $query diisi (dari filter tabel aktif), export cuma ambil baris
 * yang cocok filter itu.
 */
class StockWriteOffExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(private ?Builder $query = null)
    {
    }

    public function query(): Builder
    {
        return ($this->query ?? StockWriteOff::query())
            ->with(['creator', 'journalEntry'])
            ->reorder('created_at', 'desc');
    }

    public function headings(): array
    {
        return ['Nomor', 'Tanggal', 'Barang', 'Jenis', 'Jumlah', 'Alasan', 'Nilai Kerugian', 'Jurnal', 'Oleh', 'Catatan'];
    }

    public function map($writeOff): array
    {
        return [
            $writeOff->write_off_number ?? '-',
            $writeOff->created_at?->format('d/m/Y H:i'),
            $writeOff->item_name,
            match ($writeOff->writeoffable_type) {
                'raw_material' => 'Bahan Baku',
                'consumable_item' => 'Barang Habis Pakai',
                default => $writeOff->writeoffable_type,
            },
            number_format((float) $writeOff->quantity, 2) . ' ' . ($writeOff->unit ?? ''),
            StockWriteOff::REASON_LABELS[$writeOff->reason] ?? $writeOff->reason,
            $writeOff->total_value !== null ? (float) $writeOff->total_value : '-',
            $writeOff->journal_entry_id ? 'Ya' : 'Tidak',
            $writeOff->creator?->name ?? '-',
            $writeOff->note ?: '-',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}

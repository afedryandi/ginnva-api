<?php

namespace App\Exports;

use App\Models\InventoryItem;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Produk PPF/WF" (InventoryItemResource) — audit 2026-09-12,
 * temuan pola standar. Beda dari "Export Kode Gulungan" (ScrollCode) —
 * ini daftar unit fisik (kardus), bukan roll.
 */
class InventoryItemExport implements FromCollection, WithHeadings, WithStyles
{
    private const STATUS_LABEL = [
        'in_stock' => 'Ada Stok',
        'out' => 'Sudah Keluar',
    ];

    public function headings(): array
    {
        return ['Kode', 'Nama Produk', 'Kategori', 'Kode Gulungan', 'Sisa Panjang', 'Total Panjang', 'Tanggal Masuk', 'Status'];
    }

    public function collection(): Collection
    {
        return InventoryItem::query()
            ->with('scrollCode')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (InventoryItem $item) => [
                $item->code,
                $item->name,
                $item->category ?? '-',
                $item->scrollCode?->code ?? '-',
                $item->scrollCode?->remaining_length_meters !== null ? (float) $item->scrollCode->remaining_length_meters : '-',
                $item->scrollCode?->total_length_meters !== null ? (float) $item->scrollCode->total_length_meters : '-',
                $item->received_date?->format('Y-m-d') ?? '-',
                self::STATUS_LABEL[$item->status] ?? $item->status,
            ]);
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2937']],
            ],
        ];
    }
}

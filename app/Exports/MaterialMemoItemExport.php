<?php

namespace App\Exports;

use App\Models\MaterialMemoItem;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 1 baris = 1 barang di 1 memo (bukan 1 baris per memo) — supaya bisa
 * langsung difilter/pivot di Excel per jenis barang/toko/rentang tanggal,
 * sesuai kebutuhan umum laporan pemakaian bahan.
 */
class MaterialMemoItemExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    /**
     * Null untuk full-access (semua toko) — staff biasa yang buka menu ini
     * cuma pernah lihat memo tokonya sendiri (lihat
     * MaterialMemoResource::getEloquentQuery()), export-nya juga
     * dibatasi sama supaya tidak bocor data toko lain.
     */
    public function __construct(private readonly ?int $storeId = null)
    {
    }

    public function query(): Builder
    {
        return MaterialMemoItem::query()
            ->with(['memo.store:id,name', 'memo.creator:id,name'])
            ->join('material_memos', 'material_memos.id', '=', 'material_memo_items.material_memo_id')
            ->when($this->storeId, fn ($q) => $q->where('material_memos.store_id', $this->storeId))
            ->orderByDesc('material_memos.created_at')
            ->select('material_memo_items.*');
    }

    public function headings(): array
    {
        return [
            'No Memo', 'Tanggal Memo Dibuat', 'Tanggal Barang Diambil', 'Toko',
            'Kendaraan', 'SPK No', 'Dibuat Oleh', 'Jenis Barang', 'Nama Barang',
            'Diambil', 'Dikembalikan', 'Terpakai', 'Meter Dipakai', 'Keterangan/Kondisi',
        ];
    }

    public function map($item): array
    {
        $memo = $item->memo;

        return [
            $memo?->memo_number ?? '—',
            $memo?->created_at?->format('d/m/Y H:i') ?? '—',
            // Beda dari tanggal memo dibuat kalau barang ini ditambahkan
            // belakangan (mis. staff balik lagi ke memo yang sama besoknya
            // buat catat barang tambahan) — bukan sekadar duplikat kolom.
            $item->created_at?->format('d/m/Y H:i') ?? '—',
            $memo?->store?->name ?? '—',
            $memo?->vehicle_info ?? '—',
            $memo?->spk_number ?? '—',
            $memo?->creator?->name ?? '—',
            match ($item->item_type) {
                'raw_material' => 'Bahan Baku',
                'consumable_item' => 'Barang Habis Pakai',
                'inventory_item' => 'PPF/WF',
                default => $item->item_type,
            },
            $item->item_name,
            $item->qty_taken !== null ? "{$item->qty_taken} {$item->unit}" : '—',
            $item->qty_returned !== null ? "{$item->qty_returned} {$item->unit}" : '—',
            $item->qty_used !== null ? "{$item->qty_used} {$item->unit}" : '—',
            $item->meters_used !== null ? "{$item->meters_used} meter" : '—',
            $item->condition_notes ?? '—',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
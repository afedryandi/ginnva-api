<?php

namespace App\Exports;

use App\Models\PurchaseRequest;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Permohonan Pembelian" (PurchaseRequestResource) — audit
 * 2026-09-12, temuan pola standar. Scope store sama dengan
 * PurchaseRequestResource::getEloquentQuery() — staff non-full-access
 * cuma dapat permohonan toko sendiri di file ekspornya juga.
 */
class PurchaseRequestExport implements FromCollection, WithHeadings, WithStyles
{
    private const STATUS_LABEL = [
        'pending' => 'Menunggu Persetujuan',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        'fulfilled' => 'Terpenuhi',
        'cancelled' => 'Dibatalkan',
    ];

    private const ITEM_TYPE_LABEL = [
        'raw_material' => 'Bahan Baku',
        'consumable_item' => 'Barang Habis Pakai',
        'asset' => 'Aset Baru',
    ];

    /** $withCost: kolom Biaya Aktual hanya untuk yang boleh melihat harga; $query: daftar yang sedang difilter di layar (kosong = semua yang boleh dilihat). */
    public function __construct(private bool $withCost = true, private ?\Illuminate\Database\Eloquent\Builder $query = null)
    {
    }

    public function headings(): array
    {
        $headings = ['No. Permohonan', 'Barang', 'Jenis', 'Jumlah', 'Toko', 'Status', 'Biaya Aktual', 'Diajukan Oleh', 'Tanggal'];

        return $this->withCost ? $headings : array_values(array_diff_key($headings, [6 => true]));
    }

    public function collection(): Collection
    {
        if ($this->query) {
            $rows = $this->query->with(['store', 'requester'])->get();
        } else {
            $query = PurchaseRequest::query()->with(['store', 'requester']);
            $user = auth()->user();

            if ($user && ! $user->isFullAccess()) {
                $query->where('store_id', $user->store_id);
            }

            $rows = $query->orderByDesc('created_at')->get();
        }

        return $rows->map(fn (PurchaseRequest $request) => $this->withoutCostColumn([
            $request->request_number,
            $request->item_name,
            self::ITEM_TYPE_LABEL[$request->item_type] ?? $request->item_type,
            (float) $request->quantity . ($request->unit ? " {$request->unit}" : ''),
            $request->store?->name ?? '-',
            self::STATUS_LABEL[$request->status] ?? $request->status,
            $request->actual_cost !== null ? (float) $request->actual_cost : '-',
            $request->requester?->name ?? '-',
            $request->created_at?->format('Y-m-d') ?? '-',
        ]));
    }

    private function withoutCostColumn(array $row): array
    {
        return $this->withCost ? $row : array_values(array_diff_key($row, [6 => true]));
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

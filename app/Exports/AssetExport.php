<?php

namespace App\Exports;

use App\Models\Asset;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Aset Tetap" (AssetResource) — audit 2026-09-12, temuan pola
 * standar. Query pakai `visibleTo($user)` yang sama dengan
 * AssetResource::getEloquentQuery() — staff non-full-access cuma
 * dapat aset toko sendiri di file ekspornya juga.
 */
class AssetExport implements FromCollection, WithHeadings, WithStyles
{
    private const STATUS_LABEL = [
        'aktif' => 'Aktif Dipakai',
        'diperbaiki' => 'Sedang Diperbaiki',
        'rusak' => 'Rusak',
        'dijual' => 'Dijual',
        'hilang' => 'Hilang',
    ];

    public function headings(): array
    {
        return ['Kode', 'Nama Aset', 'Kategori', 'Status', 'Dipegang Oleh', 'Lokasi', 'Tanggal Beli', 'Harga Beli', 'Nilai Buku Saat Ini'];
    }

    public function collection(): Collection
    {
        $query = Asset::query()->with(['assignee', 'store']);
        $user = auth()->user();

        if ($user) {
            $query->visibleTo($user);
        }

        return $query->orderByDesc('created_at')->get()->map(fn (Asset $asset) => [
            $asset->asset_tag,
            $asset->name,
            $asset->category ?? '-',
            self::STATUS_LABEL[$asset->status] ?? $asset->status,
            $asset->assignee?->name ?? '-',
            $asset->store?->name ?? 'Kantor Pusat',
            $asset->purchase_date?->format('Y-m-d') ?? '-',
            $asset->purchase_cost !== null ? (float) $asset->purchase_cost : '-',
            $asset->currentBookValue() !== null ? (float) $asset->currentBookValue() : '-',
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

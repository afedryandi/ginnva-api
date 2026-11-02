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

    /** $withCost: kolom harga hanya untuk yang boleh melihat harga; $query: daftar yang sedang difilter di layar (kosong = semua aset yang boleh dilihat). */
    public function __construct(private bool $withCost = true, private ?\Illuminate\Database\Eloquent\Builder $query = null)
    {
    }

    public function headings(): array
    {
        $headings = ['Kode', 'Nama Aset', 'Kategori', 'Status', 'Dipegang Oleh', 'Lokasi', 'Tanggal Beli', 'Harga Beli', 'Nilai Buku Saat Ini'];

        return $this->withCost ? $headings : array_slice($headings, 0, 7);
    }

    public function collection(): Collection
    {
        if ($this->query) {
            $rows = $this->query->with(['assignee', 'store'])->get();
        } else {
            $query = Asset::query()->with(['assignee', 'store']);
            $user = auth()->user();

            if ($user) {
                $query->visibleTo($user);
            }

            $rows = $query->orderByDesc('created_at')->get();
        }

        return $rows->map(fn (Asset $asset) => $this->withoutCostColumns([
            $asset->asset_tag,
            $asset->name,
            $asset->category ?? '-',
            self::STATUS_LABEL[$asset->status] ?? $asset->status,
            $asset->assignee?->name ?? '-',
            $asset->store?->name ?? 'Kantor Pusat',
            $asset->purchase_date?->format('Y-m-d') ?? '-',
            $asset->purchase_cost !== null ? (float) $asset->purchase_cost : '-',
            $asset->currentBookValue() !== null ? (float) $asset->currentBookValue() : '-',
        ]));
    }

    private function withoutCostColumns(array $row): array
    {
        return $this->withCost ? $row : array_slice($row, 0, 7);
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

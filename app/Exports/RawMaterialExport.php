<?php

namespace App\Exports;

use App\Models\RawMaterial;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Daftar Bahan Baku" (RawMaterialResource) — audit 2026-09-12,
 * temuan pola standar.
 */
class RawMaterialExport implements FromCollection, WithHeadings, WithStyles
{
    /** Kolom harga hanya untuk yang boleh melihat harga modal (RawMaterialResource::canSeeCost()). */
    /** $query: daftar yang sedang difilter/dicari di layar; kosong = semua bahan menurut abjad. */
    public function __construct(private bool $withCost = true, private ?\Illuminate\Database\Eloquent\Builder $query = null)
    {
    }

    public function headings(): array
    {
        $headings = ['Kode', 'Nama Bahan', 'Kategori', 'Stok (Total)', 'Satuan', 'Ambang Menipis', 'Harga/Satuan', 'Kedaluwarsa Terdekat'];

        return $this->withCost ? $headings : array_values(array_diff_key($headings, [6 => true]));
    }

    public function collection(): Collection
    {
        return ($this->query ? $this->query->get() : RawMaterial::query()->orderBy('name')->get())
            ->map(fn (RawMaterial $material) => $this->withoutCostColumn([
                $material->code ?? '-',
                $material->name,
                $material->category ?? '-',
                (float) $material->current_stock,
                $material->unit,
                $material->reorder_point !== null ? (float) $material->reorder_point : '-',
                $material->unit_cost !== null ? (float) $material->unit_cost : '-',
                $material->earliestActiveExpiryDate()?->format('Y-m-d') ?? '-',
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

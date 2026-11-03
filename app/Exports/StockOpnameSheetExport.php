<?php

namespace App\Exports;

use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lembar hitung Stok Opname (audit 2026-11-03): daftar barang dengan kolom "Hasil Hitung" dan "Catatan" kosong, untuk dibawa ke gudang lalu
 * diisi dan diimpor kembali. Stok sistem SENGAJA tidak dicantumkan (hitung buta). Kolom Jenis + ID dipakai impor untuk mengenali barang.
 */
class StockOpnameSheetExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    /** @param Collection<int, array<string, mixed>> $rows */
    public function __construct(private Collection $rows)
    {
    }

    /**
     * Barang aktif (atau nonaktif yang masih berstok), urut jenis lalu kategori lalu nama.
     *
     * @return Collection<int, array{jenis: string, id: int, code: ?string, name: string, category: ?string, unit: string}>
     */
    public static function rows(string $jenis = 'all', ?string $category = null): Collection
    {
        $rows = collect();

        foreach ([['raw_material', 'Bahan Baku', RawMaterial::class], ['consumable_item', 'Barang Habis Pakai', ConsumableItem::class]] as [$key, $label, $model]) {
            if ($jenis !== 'all' && $jenis !== $key) {
                continue;
            }

            $model::query()
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('current_stock', '>', 0))
                ->when($category, fn ($q) => $q->where('category', $category))
                ->orderBy('category')->orderBy('name')
                ->get(['id', 'name', 'code', 'category', 'unit'])
                ->each(fn ($m) => $rows->push(['jenis' => $label, 'id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'category' => $m->category, 'unit' => $m->unit]));
        }

        return $rows;
    }

    public function headings(): array
    {
        return ['Jenis', 'ID', 'Kode', 'Nama', 'Kategori', 'Satuan', 'Hasil Hitung', 'Catatan'];
    }

    public function array(): array
    {
        return $this->rows->map(fn (array $r) => [$r['jenis'], $r['id'], $r['code'] ?: '-', $r['name'], $r['category'] ?: '-', $r['unit'], null, null])->all();
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
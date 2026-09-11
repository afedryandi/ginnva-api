<?php

namespace App\Exports;

use App\Models\ConsumableItemMovement;
use App\Models\RawMaterialMovement;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Lap. Detail Persediaan" (PersediaanDetailReport) — audit
 * 2026-09-11, temuan B. Gabung Bahan Baku + Barang Habis Pakai jadi 1
 * sheet, kolom "Jenis Item" membedakan keduanya.
 */
class PersediaanDetailReportExport implements FromArray, WithHeadings, WithStyles
{
    private const TYPE_LABEL = [
        'in' => 'Masuk',
        'out' => 'Keluar',
        'adjustment' => 'Penyesuaian',
    ];

    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Tanggal',
            'Jenis Item',
            'Nama',
            'Pergerakan',
            'Jumlah',
            'Harga Beli',
            'Oleh',
            'Catatan',
        ];
    }

    public function array(): array
    {
        $materials = collect($this->result['materialMovements'])
            ->map(fn (RawMaterialMovement $m) => [
                $m->created_at->format('Y-m-d H:i'),
                'Bahan Baku',
                $m->rawMaterial?->name ?? '-',
                self::TYPE_LABEL[$m->type] ?? $m->type,
                (float) $m->quantity . ' ' . ($m->rawMaterial?->unit ?? ''),
                $m->unit_cost ? (float) $m->unit_cost : '-',
                $m->user?->name ?? '-',
                $m->note ?: '-',
            ]);

        $consumables = collect($this->result['consumableMovements'])
            ->map(fn (ConsumableItemMovement $c) => [
                $c->created_at->format('Y-m-d H:i'),
                'Barang Habis Pakai',
                $c->consumableItem?->name ?? '-',
                self::TYPE_LABEL[$c->type] ?? $c->type,
                (float) $c->quantity . ' ' . ($c->consumableItem?->unit ?? ''),
                $c->unit_cost ? (float) $c->unit_cost : '-',
                $c->user?->name ?? '-',
                $c->note ?: '-',
            ]);

        return $materials->concat($consumables)->values()->all();
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1F2937'],
                ],
            ],
        ];
    }
}

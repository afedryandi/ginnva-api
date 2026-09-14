<?php

namespace App\Exports;

use App\Models\Reward;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Katalog Reward" (RewardResource) — audit 2026-09-14, temuan
 * pola standar.
 */
class RewardExport implements FromCollection, WithHeadings, WithStyles
{
    public function headings(): array
    {
        return ['Nama', 'Harga Poin', 'Stok', 'Aktif'];
    }

    public function collection(): Collection
    {
        return Reward::query()
            ->orderBy('points_cost')
            ->get()
            ->map(fn (Reward $reward) => [
                $reward->name,
                $reward->points_cost,
                $reward->stock ?? 'Tanpa batas',
                $reward->is_active ? 'Ya' : 'Tidak',
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

<?php

namespace App\Exports;

use App\Models\RawMaterialBatch;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Stok Kedaluwarsa" (ExpiringStockReport) — audit
 * 2026-09-11, temuan B.
 */
class ExpiringStockReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'SKU',
            'Bahan Baku',
            'Tanggal Terima',
            'Tanggal Kedaluwarsa',
            'Sisa Qty',
            'Nilai',
            'Status',
        ];
    }

    public function array(): array
    {
        $today = now()->startOfDay();

        return collect($this->result['batches'])
            ->map(function (RawMaterialBatch $batch) use ($today) {
                $isExpired = $batch->expiry_date->lt($today);
                $days = $today->diffInDays($batch->expiry_date);

                return [
                    $batch->rawMaterial?->code ?? '-',
                    $batch->rawMaterial?->name ?? '-',
                    $batch->received_date?->format('Y-m-d') ?? '-',
                    $batch->expiry_date->format('Y-m-d'),
                    (float) $batch->quantity,
                    (float) $batch->quantity * (float) ($batch->unit_cost ?? 0),
                    $isExpired ? "Kedaluwarsa ({$days} hari lalu)" : "Kedaluwarsa dalam {$days} hari",
                ];
            })
            ->values()
            ->all();
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

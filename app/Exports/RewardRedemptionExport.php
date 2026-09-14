<?php

namespace App\Exports;

use App\Models\RewardRedemption;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Klaim Reward" (RewardRedemptionResource) — audit
 * 2026-09-14, temuan pola standar.
 */
class RewardRedemptionExport implements FromCollection, WithHeadings, WithStyles
{
    private const STATUS_LABEL = [
        'pending' => 'Menunggu Diproses',
        'fulfilled' => 'Sudah Dikirim',
        'cancelled' => 'Dibatalkan',
    ];

    private const TYPE_LABEL = [
        'partner' => 'Partner',
        'customer' => 'Customer',
    ];

    public function headings(): array
    {
        return ['Ditukar Oleh', 'Tipe', 'Reward', 'Poin', 'Status', 'Catatan Admin', 'Tanggal'];
    }

    public function collection(): Collection
    {
        return RewardRedemption::query()
            ->with('reward')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (RewardRedemption $redemption) => [
                $redemption->redeemer_name,
                self::TYPE_LABEL[$redemption->redeemer_type] ?? $redemption->redeemer_type,
                $redemption->reward?->name ?? '-',
                $redemption->points_spent,
                self::STATUS_LABEL[$redemption->status] ?? $redemption->status,
                $redemption->notes ?: '-',
                $redemption->created_at?->format('Y-m-d H:i'),
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

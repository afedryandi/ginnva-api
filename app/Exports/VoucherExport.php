<?php

namespace App\Exports;

use App\Models\Voucher;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Voucher Promo" (VoucherResource) — audit 2026-09-14, temuan
 * pola standar.
 */
class VoucherExport implements FromCollection, WithHeadings, WithStyles
{
    public function headings(): array
    {
        return ['Nama Kampanye', 'Potongan', 'Ter-assign', 'Total Stok', 'Kedaluwarsa', 'Aktif'];
    }

    public function collection(): Collection
    {
        return Voucher::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Voucher $voucher) => [
                $voucher->name,
                (float) $voucher->discount_amount,
                $voucher->claimed_count,
                $voucher->total_stock,
                $voucher->expires_at?->format('Y-m-d H:i') ?? 'Tanpa batas waktu',
                $voucher->is_active ? 'Ya' : 'Tidak',
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

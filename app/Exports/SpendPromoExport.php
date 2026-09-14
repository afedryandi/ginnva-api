<?php

namespace App\Exports;

use App\Models\SpendPromo;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Promo Total Pembelian" (SpendPromoResource) — audit
 * 2026-09-14, temuan pola standar.
 */
class SpendPromoExport implements FromCollection, WithHeadings, WithStyles
{
    public function headings(): array
    {
        return ['Nama', 'Minimal Pembelian', 'Potongan', 'Mulai Berlaku', 'Berakhir', 'Dipakai (Booking)', 'Status'];
    }

    public function collection(): Collection
    {
        return SpendPromo::query()
            ->withCount('bookings')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SpendPromo $promo) => [
                $promo->name,
                (float) $promo->min_purchase_amount,
                (float) $promo->discount_amount,
                $promo->starts_on?->format('Y-m-d') ?? '-',
                $promo->ends_on?->format('Y-m-d') ?? '-',
                $promo->bookings_count,
                ! $promo->is_active ? 'Nonaktif' : ($promo->isRunning() ? 'Berjalan' : 'Terjadwal / Lewat'),
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

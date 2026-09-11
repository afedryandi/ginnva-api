<?php

namespace App\Exports;

use App\Models\Refund;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Refund" (RefundReport) — audit 2026-09-11, temuan B.
 */
class RefundReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'No. Refund',
            'Tanggal',
            'No. Booking',
            'Pelanggan',
            'Toko',
            'Metode Pembayaran',
            'Diproses Oleh',
            'No. Jurnal',
            'Alasan',
            'Nominal',
        ];
    }

    public function array(): array
    {
        return collect($this->result['refunds'])
            ->map(fn (Refund $refund) => [
                $refund->refund_number,
                $refund->created_at->format('Y-m-d H:i'),
                $refund->booking?->booking_number ?? '-',
                $refund->booking?->customer_name ?? '-',
                $refund->booking?->store?->name ?? '-',
                'Tunai',
                $refund->creator?->name ?? '-',
                $refund->journalEntry?->entry_number ?? '-',
                $refund->reason ?: '-',
                (float) $refund->amount,
            ])
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
                    'startColor' => ['rgb' => '991B1B'],
                ],
            ],
        ];
    }
}

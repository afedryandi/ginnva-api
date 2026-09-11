<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;

/**
 * Export "Laporan Void" (VoidReport) — audit 2026-09-11, temuan B.
 * FromArray karena sumbernya Collection hasil getResult() (activity_log
 * + relasi Booking), bukan Eloquent Builder biasa.
 */
class VoidReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'No. Booking',
            'Tanggal Order',
            'Tanggal Dibatalkan',
            'Pelanggan',
            'Toko',
            'Layanan',
            'Dibatalkan Oleh',
            'Nilai Transaksi',
        ];
    }

    public function array(): array
    {
        return collect($this->result['events'])
            ->map(function (Activity $event) {
                $booking = $event->subject;

                return [
                    $booking->booking_number,
                    optional($booking->created_at)->format('Y-m-d H:i'),
                    $event->created_at->format('Y-m-d H:i'),
                    $booking->customer_name ?? '-',
                    $booking->store?->name ?? '-',
                    match (true) {
                        $booking->product_kaca_film && $booking->product_ppf => 'Kaca Film + PPF',
                        $booking->product_ppf => 'PPF',
                        $booking->product_kaca_film => 'Kaca Film',
                        default => '-',
                    },
                    $event->causer?->name ?? 'Sistem (otomatis)',
                    (float) $booking->transaction_amount,
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
                    'startColor' => ['rgb' => '991B1B'],
                ],
            ],
        ];
    }
}

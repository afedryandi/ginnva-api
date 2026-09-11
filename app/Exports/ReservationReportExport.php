<?php

namespace App\Exports;

use App\Models\Booking;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Reservasi" (ReservationReport) — audit 2026-09-11,
 * temuan B.
 */
class ReservationReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'No. Booking',
            'Tanggal Buat',
            'Tanggal Diinginkan',
            'Durasi (hari)',
            'Pelanggan',
            'Toko',
            'Layanan',
            'Teknisi',
            'Total Tagihan',
            'Status',
        ];
    }

    public function array(): array
    {
        return collect($this->result['bookings'])
            ->map(fn (Booking $booking) => [
                $booking->booking_number,
                optional($booking->created_at)->format('Y-m-d'),
                $booking->preferred_date?->format('Y-m-d'),
                $booking->duration_days,
                $booking->customer_name ?? '-',
                $booking->store?->name ?? '-',
                match (true) {
                    $booking->product_kaca_film && $booking->product_ppf => 'Kaca Film + PPF',
                    $booking->product_ppf => 'PPF',
                    $booking->product_kaca_film => 'Kaca Film',
                    default => '-',
                },
                $booking->installers->pluck('name')->join(', ') ?: '-',
                (float) ($booking->transaction_amount ?? 0),
                $booking->status === 'confirmed' ? 'Terkonfirmasi' : 'Menunggu Approval',
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
                    'startColor' => ['rgb' => '1F2937'],
                ],
            ],
        ];
    }
}

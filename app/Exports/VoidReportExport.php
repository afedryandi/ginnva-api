<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Pembatalan" (VoidReport). FromArray karena sumbernya hasil getResult(), bukan Eloquent Builder biasa.
 * Baris terakhir = Total (dibuat hanya bila ada data).
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
            'Jenis',
            'Pelanggan',
            'Toko',
            'Layanan',
            'Dibatalkan Oleh',
            'Alasan',
            'Nilai Transaksi',
            'DP Belum Dikembalikan',
        ];
    }

    public function array(): array
    {
        $rows = collect($this->result['rows'])
            ->map(function (array $row) {
                $booking = $row['booking'];

                return [
                    $booking->booking_number,
                    optional($booking->created_at)->format('Y-m-d H:i'),
                    $row['cancelled_at']->format('Y-m-d H:i'),
                    $row['kind'],
                    $booking->display_customer_name,
                    $booking->store?->name ?? '-',
                    $booking->salesProductLabel() ?? '-',
                    $row['by'],
                    $row['reason'] ?? '-',
                    $row['amount'],
                    $row['dp_open'],
                ];
            })
            ->values()
            ->all();

        if ($rows !== []) {
            $rows[] = ['Total', '', '', '', '', '', '', '', '', (float) $this->result['totalLostRevenue'], (float) $this->result['openDpTotal']];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '991B1B'],
                ],
            ],
        ];

        if (count($this->result['rows']) > 0) {
            $styles[count($this->result['rows']) + 2] = ['font' => ['bold' => true]];
        }

        return $styles;
    }
}

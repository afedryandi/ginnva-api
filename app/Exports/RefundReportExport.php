<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Refund" (RefundReport): refund penjualan dan refund DP dalam satu daftar, baris Total di ujung.
 * Kolom No. Jurnal hanya ada bila modul Keuangan menyala.
 */
class RefundReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return array_keys($this->columns());
    }

    public function array(): array
    {
        $rows = collect($this->result['rows'])
            ->map(function (array $row) {
                $booking = $row['booking'];
                $cells = [
                    'No. Refund' => $row['number'] ?? '-',
                    'Tanggal' => $row['date']->format('Y-m-d H:i'),
                    'Jenis' => $row['kind'],
                    'No. Booking' => $booking?->booking_number ?? '-',
                    'Pelanggan' => $booking?->display_customer_name ?? '-',
                    'Toko' => $booking?->store?->name ?? '-',
                    'Metode' => $row['method_label'],
                    'Diproses Oleh' => $row['by'] ?? '-',
                    'Disetujui Oleh' => $row['approved_by'] ?? '-',
                    'No. Jurnal' => $row['journal'] ?? '-',
                    'Alasan' => $row['reason'] ?: '-',
                    'Nominal' => $row['amount'],
                    'Sisa Setelah Refund' => $row['remaining'] ?? '-',
                ];

                return array_values(array_intersect_key($cells, $this->columns()));
            })
            ->values()
            ->all();

        if ($rows !== []) {
            $total = array_map(fn () => '', $this->columns());
            $total['No. Refund'] = 'Total';
            $total['Nominal'] = (float) $this->result['totalAmount'];
            $rows[] = array_values($total);
        }

        return $rows;
    }

    /** @return array<string, true> kolom yang ditampilkan, berurutan. */
    private function columns(): array
    {
        $columns = array_fill_keys(['No. Refund', 'Tanggal', 'Jenis', 'No. Booking', 'Pelanggan', 'Toko', 'Metode', 'Diproses Oleh', 'Disetujui Oleh', 'No. Jurnal', 'Alasan', 'Nominal', 'Sisa Setelah Refund'], true);

        if (! config('features.finance')) {
            unset($columns['No. Jurnal']);
        }

        return $columns;
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

<?php

namespace App\Exports;

use App\Models\Booking;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Detail Penjualan" (SalesResource) — diminta 2026-09-09, analog
 * tombol "Ekspor Laporan" di halaman Penjualan Majoo.
 *
 * FromQuery + terima Builder dari $livewire->getFilteredTableQuery()
 * (pola sama dengan WarrantyExport/InventoryMovementExport dkk) supaya
 * hasil export SELALU konsisten dengan filter yang sedang aktif di
 * layar (rentang tanggal, toko) — bukan query independen yang menarik
 * semua data.
 *
 * Kolom piutang (Diterima, Sisa Tagihan, Status) dan No. Jurnal hanya ada bila modul Keuangan menyala, sama dengan layar.
 * Nomor turunan "INV/<no. booking>" dan status Void dihapus 2026-11-04 (lihat SalesResource).
 */
class SalesExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    public function __construct(private ?Builder $query = null) {}

    public function query(): Builder
    {
        return ($this->query ?? Booking::query()->sold())
            ->with(['store', 'journalEntry', 'customer']);
    }

    public function headings(): array
    {
        $headings = [
            'No. Booking',
            'Pelanggan',
            'Toko',
            'Produk',
            'Nilai Transaksi',
            'Potongan Promo',
        ];

        if (config('features.finance')) {
            array_push($headings, 'Diterima', 'Sisa Tagihan', 'Status');
        }

        array_push($headings, 'Waktu Order', 'Tanggal Penjualan');

        if (config('features.finance')) {
            $headings[] = 'No. Jurnal';
        }

        return $headings;
    }

    /**
     * @param Booking $booking
     */
    public function map($booking): array
    {
        $received = $booking->amount_received !== null ? (float) $booking->amount_received : (float) $booking->transaction_amount;
        $outstanding = max(0, (float) $booking->transaction_amount - $received);

        $row = [
            $booking->booking_number,
            $booking->display_customer_name,
            $booking->store?->name ?? '-',
            $booking->salesProductLabel() ?? '-',
            (float) $booking->transaction_amount,
            (float) ($booking->spend_promo_discount ?? 0),
        ];

        if (config('features.finance')) {
            array_push($row, $received, $outstanding, $outstanding > 0.009 ? 'Belum Lunas' : 'Lunas');
        }

        array_push($row, optional($booking->created_at)->format('Y-m-d H:i'), optional($booking->sale_day)->format('Y-m-d'));

        if (config('features.finance')) {
            $row[] = $booking->journalEntry?->entry_number ?? '-';
        }

        return $row;
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

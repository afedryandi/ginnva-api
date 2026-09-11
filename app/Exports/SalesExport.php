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
 */
class SalesExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    public function __construct(private ?Builder $query = null) {}

    public function query(): Builder
    {
        return ($this->query ?? Booking::query()->whereHas('journalEntry'))
            ->with(['store', 'journalEntry']);
    }

    public function headings(): array
    {
        return [
            'No. Invoice',
            'No. Booking',
            'Pelanggan',
            'Toko',
            'Produk',
            'Nilai Transaksi',
            'Potongan Promo',
            'Diterima',
            'Sisa Tagihan',
            'Status',
            'Waktu Order',
            'Waktu Bayar',
            'No. Jurnal',
        ];
    }

    /**
     * @param Booking $booking
     */
    public function map($booking): array
    {
        $received = $booking->amount_received !== null ? (float) $booking->amount_received : (float) $booking->transaction_amount;
        $outstanding = max(0, (float) $booking->transaction_amount - $received);

        $status = $booking->status === 'cancelled'
            ? 'Void'
            : ($outstanding > 0.009 ? 'Belum Lunas' : 'Lunas');

        return [
            'INV/' . $booking->booking_number,
            $booking->booking_number,
            $booking->customer_name ?? '-',
            $booking->store?->name ?? '-',
            match (true) {
                $booking->product_kaca_film && $booking->product_ppf => 'Kaca Film + PPF',
                $booking->product_ppf => 'PPF',
                $booking->product_kaca_film => 'Kaca Film',
                default => '-',
            },
            (float) $booking->transaction_amount,
            (float) ($booking->spend_promo_discount ?? 0),
            $received,
            $outstanding,
            $status,
            optional($booking->created_at)->format('Y-m-d H:i'),
            optional($booking->journalEntry?->entry_date)->format('Y-m-d'),
            $booking->journalEntry?->entry_number ?? '-',
        ];
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

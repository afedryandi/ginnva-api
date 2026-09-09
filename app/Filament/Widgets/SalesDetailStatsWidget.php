<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SalesResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Stat card di atas tabel "Detail Penjualan" — diminta 2026-09-09,
 * analog 5 kartu (Total Penjualan/Transaksi/Penjualan Bersih/Pembayaran/
 * Piutang) di halaman Detail Penjualan Majoo.
 *
 * KETERBATASAN YANG DISENGAJA: dihitung dari SalesResource::getEloquentQuery()
 * (base scope toko/akses user), BUKAN reaktif mengikuti filter tanggal/
 * pencarian yang sedang diketik di tabel bawahnya — Filament tidak
 * menyediakan cara resmi yang saya bisa pastikan (tanpa akses
 * vendor/browser di sandbox ini) untuk menyinkronkan StatsOverviewWidget
 * dengan state filter live sebuah ListRecords table tanpa menebak
 * struktur internal. Jadi kartu ini tampilkan total KESELURUHAN data
 * yang bisa diakses user, bukan "hasil filter saat ini" seperti Majoo.
 */
class SalesDetailStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    public static function canView(): bool
    {
        return SalesResource::canViewAny();
    }

    protected function getStats(): array
    {
        $bookings = SalesResource::getEloquentQuery()->get(['transaction_amount', 'amount_received']);

        $revenue = (float) $bookings->sum('transaction_amount');
        $received = (float) $bookings->sum(fn ($b) => $b->amount_received !== null ? (float) $b->amount_received : (float) $b->transaction_amount);
        $outstanding = max(0, $revenue - $received);
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');

        return [
            Stat::make('Total Penjualan', $rupiah($revenue)),
            Stat::make('Total Transaksi', number_format($bookings->count(), 0, ',', '.')),
            // Penjualan Bersih = Total Penjualan (tidak ada pengurang apa
            // pun, refund belum ada mekanismenya di sistem).
            Stat::make('Penjualan Bersih', $rupiah($revenue)),
            Stat::make('Total Diterima', $rupiah($received)),
            Stat::make('Total Piutang', $rupiah($outstanding))
                ->color($outstanding > 0 ? 'danger' : 'gray'),
        ];
    }
}

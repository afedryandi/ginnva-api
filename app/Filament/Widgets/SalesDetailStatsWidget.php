<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SalesResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Stat card di atas tabel "Detail Penjualan" — diminta 2026-09-09,
 * dilengkapi 2026-09-10 jadi kartu ala "Daftar Invoice" Majoo:
 * Total Invoice / Lunas / Belum Lunas / Void / Total Diterima.
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
        $bookings = SalesResource::getEloquentQuery()->get(['transaction_amount', 'amount_received', 'status']);

        $revenue = (float) $bookings->sum('transaction_amount');
        $received = (float) $bookings->sum(fn ($b) => $b->amount_received !== null ? (float) $b->amount_received : (float) $b->transaction_amount);
        $outstanding = max(0, $revenue - $received);
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');

        $void = $bookings->where('status', 'cancelled');
        $active = $bookings->where('status', '!=', 'cancelled');
        $belumLunas = $active->filter(fn ($b) => ((float) $b->transaction_amount - (float) ($b->amount_received ?? $b->transaction_amount)) > 0.009);
        $lunas = $active->reject(fn ($b) => ((float) $b->transaction_amount - (float) ($b->amount_received ?? $b->transaction_amount)) > 0.009);

        return [
            Stat::make('Total Invoice', $rupiah($revenue))
                ->description(number_format($bookings->count(), 0, ',', '.') . ' invoice'),
            Stat::make('Lunas', $rupiah((float) $lunas->sum('transaction_amount')))
                ->description($lunas->count() . ' invoice')
                ->color('success'),
            Stat::make('Belum Lunas', $rupiah((float) $belumLunas->sum(fn ($b) => (float) $b->transaction_amount - (float) ($b->amount_received ?? $b->transaction_amount))))
                ->description($belumLunas->count() . ' invoice')
                ->color($belumLunas->isNotEmpty() ? 'warning' : 'gray'),
            Stat::make('Void', $rupiah((float) $void->sum('transaction_amount')))
                ->description($void->count() . ' invoice')
                ->color($void->isNotEmpty() ? 'danger' : 'gray'),
            Stat::make('Total Diterima', $rupiah($received)),
        ];
    }
}

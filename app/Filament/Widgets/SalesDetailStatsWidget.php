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
 *
 * PERFORMA (audit 2026-09-11, temuan #2): SEBELUMNYA getStats() tarik
 * SEMUA baris yang bisa diakses user ke PHP (->get([...]) lalu sum/
 * filter di Collection) — sama kelas masalah dengan bug performa
 * SalesDashboard sebelum P2. Sekarang 1 query agregat SQL
 * (COUNT/SUM/CASE, ->toBase()->first()) — DB yang hitung, bukan PHP
 * yang tarik semua baris lalu hitung. Ambang "belum lunas" (selisih >
 * 0.009) direplikasi PERSIS di SQL supaya hasilnya identik dengan
 * logika PHP yang dipakai kolom 'payment_status' di tabel & SalesExport.
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
        $agg = SalesResource::getEloquentQuery()
            ->selectRaw(
                'COUNT(*) as total_count,'
                . ' COALESCE(SUM(bookings.transaction_amount), 0) as total_revenue,'
                . ' COALESCE(SUM(COALESCE(bookings.amount_received, bookings.transaction_amount)), 0) as total_received,'

                . ' COUNT(CASE WHEN bookings.status = \'cancelled\' THEN 1 END) as void_count,'
                . ' COALESCE(SUM(CASE WHEN bookings.status = \'cancelled\' THEN bookings.transaction_amount ELSE 0 END), 0) as void_amount,'

                . ' COUNT(CASE WHEN bookings.status != \'cancelled\''
                . ' AND (bookings.transaction_amount - COALESCE(bookings.amount_received, bookings.transaction_amount)) > 0.009'
                . ' THEN 1 END) as belum_lunas_count,'
                . ' COALESCE(SUM(CASE WHEN bookings.status != \'cancelled\''
                . ' AND (bookings.transaction_amount - COALESCE(bookings.amount_received, bookings.transaction_amount)) > 0.009'
                . ' THEN (bookings.transaction_amount - COALESCE(bookings.amount_received, bookings.transaction_amount)) ELSE 0 END), 0) as belum_lunas_amount,'

                . ' COUNT(CASE WHEN bookings.status != \'cancelled\''
                . ' AND (bookings.transaction_amount - COALESCE(bookings.amount_received, bookings.transaction_amount)) <= 0.009'
                . ' THEN 1 END) as lunas_count,'
                . ' COALESCE(SUM(CASE WHEN bookings.status != \'cancelled\''
                . ' AND (bookings.transaction_amount - COALESCE(bookings.amount_received, bookings.transaction_amount)) <= 0.009'
                . ' THEN bookings.transaction_amount ELSE 0 END), 0) as lunas_amount'
            )
            ->toBase()
            ->first();

        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');

        return [
            Stat::make('Total Invoice', $rupiah($agg->total_revenue))
                ->description(number_format((int) $agg->total_count, 0, ',', '.') . ' invoice'),
            Stat::make('Lunas', $rupiah($agg->lunas_amount))
                ->description(number_format((int) $agg->lunas_count, 0, ',', '.') . ' invoice')
                ->color('success'),
            Stat::make('Belum Lunas', $rupiah($agg->belum_lunas_amount))
                ->description(number_format((int) $agg->belum_lunas_count, 0, ',', '.') . ' invoice')
                ->color((int) $agg->belum_lunas_count > 0 ? 'warning' : 'gray'),
            Stat::make('Void', $rupiah($agg->void_amount))
                ->description(number_format((int) $agg->void_count, 0, ',', '.') . ' invoice')
                ->color((int) $agg->void_count > 0 ? 'danger' : 'gray'),
            Stat::make('Total Diterima', $rupiah($agg->total_received)),
        ];
    }
}

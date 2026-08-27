<?php

namespace App\Filament\Resources\InventoryMovementResource\Widgets;

use App\Models\InventoryMovement;
use App\Models\Store;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ringkasan bulan berjalan di atas tabel riwayat — sebelumnya modul ini
 * murni daftar baris mentah, admin harus filter+hitung manual untuk tahu
 * "berapa unit keluar bulan ini". Cuma ringkasan periode berjalan (bukan
 * laporan lintas-periode penuh) supaya tetap ringan dan tidak butuh UI
 * pemilih rentang tambahan — untuk periode lain, filter tanggal + Export
 * Excel yang sudah ada di halaman ini yang dipakai.
 */
class InventoryMovementStatsOverview extends BaseWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $startOfMonth = now()->startOfMonth();

        $outCount = InventoryMovement::where('type', 'out')->where('created_at', '>=', $startOfMonth)->count();
        $inCount = InventoryMovement::where('type', 'in')->where('created_at', '>=', $startOfMonth)->count();

        $topStore = InventoryMovement::where('type', 'out')
            ->where('created_at', '>=', $startOfMonth)
            ->whereNotNull('destination_store_id')
            ->selectRaw('destination_store_id, COUNT(*) as total')
            ->groupBy('destination_store_id')
            ->orderByDesc('total')
            ->first();

        $topStoreName = $topStore ? Store::find($topStore->destination_store_id)?->name : null;

        return [
            Stat::make('Keluar Bulan Ini', $outCount)
                ->description('Sejak ' . $startOfMonth->format('d M Y'))
                ->descriptionIcon('heroicon-m-arrow-up-circle')
                ->color('danger'),

            Stat::make('Masuk Bulan Ini', $inCount)
                ->description('Sejak ' . $startOfMonth->format('d M Y'))
                ->descriptionIcon('heroicon-m-arrow-down-circle')
                ->color('success'),

            Stat::make('Toko Tujuan Terbanyak', $topStoreName ?? '—')
                ->description($topStore ? "{$topStore->total} unit keluar bulan ini" : 'Belum ada data toko tujuan bulan ini')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('info'),
        ];
    }
}

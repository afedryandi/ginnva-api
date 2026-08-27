<?php

namespace App\Filament\Resources\RawMaterialMovementResource\Widgets;

use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ringkasan konsumsi bulan berjalan — sama pola dengan
 * InventoryMovementStatsOverview (modul PPF/WF). Sebelumnya modul ini
 * murni daftar baris mentah, tidak ada cara cepat tahu "bahan apa yang
 * paling banyak keluar bulan ini" tanpa filter+hitung manual.
 */
class RawMaterialMovementStatsOverview extends BaseWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $startOfMonth = now()->startOfMonth();

        $outCount = RawMaterialMovement::where('type', 'out')->where('created_at', '>=', $startOfMonth)->count();
        $inCount = RawMaterialMovement::where('type', 'in')->where('created_at', '>=', $startOfMonth)->count();

        $topConsumed = RawMaterialMovement::where('type', 'out')
            ->where('created_at', '>=', $startOfMonth)
            ->selectRaw('raw_material_id, SUM(quantity) as total')
            ->groupBy('raw_material_id')
            ->orderByDesc('total')
            ->first();

        $topMaterial = $topConsumed ? RawMaterial::find($topConsumed->raw_material_id) : null;

        return [
            Stat::make('Keluar Bulan Ini', $outCount)
                ->description('Jumlah kejadian, sejak ' . $startOfMonth->format('d M Y'))
                ->descriptionIcon('heroicon-m-arrow-up-circle')
                ->color('danger'),

            Stat::make('Masuk Bulan Ini', $inCount)
                ->description('Jumlah kejadian, sejak ' . $startOfMonth->format('d M Y'))
                ->descriptionIcon('heroicon-m-arrow-down-circle')
                ->color('success'),

            Stat::make('Paling Banyak Dipakai', $topMaterial?->name ?? '—')
                ->description($topConsumed ? number_format((float) $topConsumed->total, 2) . ' ' . ($topMaterial?->unit ?? '') . ' bulan ini' : 'Belum ada pemakaian bulan ini')
                ->descriptionIcon('heroicon-m-beaker')
                ->color('info'),
        ];
    }
}

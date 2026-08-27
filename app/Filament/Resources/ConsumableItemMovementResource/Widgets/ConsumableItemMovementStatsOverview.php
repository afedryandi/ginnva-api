<?php

namespace App\Filament\Resources\ConsumableItemMovementResource\Widgets;

use App\Models\ConsumableItem;
use App\Models\ConsumableItemMovement;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Sama pola dengan RawMaterialMovementStatsOverview/InventoryMovementStatsOverview
 * — ringkasan bulan berjalan di atas tabel riwayat, sebelumnya modul ini
 * satu-satunya dari 3 modul movement yang belum punya widget ini.
 */
class ConsumableItemMovementStatsOverview extends BaseWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $startOfMonth = now()->startOfMonth();

        $outCount = ConsumableItemMovement::where('type', 'out')->where('created_at', '>=', $startOfMonth)->count();
        $inCount = ConsumableItemMovement::where('type', 'in')->where('created_at', '>=', $startOfMonth)->count();

        $topConsumed = ConsumableItemMovement::where('type', 'out')
            ->where('created_at', '>=', $startOfMonth)
            ->selectRaw('consumable_item_id, SUM(quantity) as total')
            ->groupBy('consumable_item_id')
            ->orderByDesc('total')
            ->first();

        $topItem = $topConsumed ? ConsumableItem::find($topConsumed->consumable_item_id) : null;

        return [
            Stat::make('Keluar Bulan Ini', $outCount)
                ->description('Jumlah kejadian, sejak ' . $startOfMonth->format('d M Y'))
                ->descriptionIcon('heroicon-m-arrow-up-circle')
                ->color('danger'),

            Stat::make('Masuk Bulan Ini', $inCount)
                ->description('Jumlah kejadian, sejak ' . $startOfMonth->format('d M Y'))
                ->descriptionIcon('heroicon-m-arrow-down-circle')
                ->color('success'),

            Stat::make('Paling Banyak Dipakai', $topItem?->name ?? '—')
                ->description($topConsumed ? number_format((float) $topConsumed->total, 2) . ' ' . ($topItem?->unit ?? '') . ' bulan ini' : 'Belum ada pemakaian bulan ini')
                ->descriptionIcon('heroicon-m-cube-transparent')
                ->color('info'),
        ];
    }
}

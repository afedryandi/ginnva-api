<?php

namespace App\Filament\Resources\StockWriteOffResource\Widgets;

use App\Filament\Resources\StockWriteOffResource;
use App\Models\StockWriteOff;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ringkasan bulan berjalan di atas daftar Stok Terbuang: jumlah kejadian, total nilai kerugian (hanya bagi yang boleh melihat nilai),
 * dan alasan terbanyak. Yang dibatalkan tidak dihitung (scope 'effective'); staf non-akses-penuh hanya melihat tokonya (StoreScope).
 */
class StockWriteOffStatsOverview extends BaseWidget
{
    protected static ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $start = now()->startOfMonth();
        $month = fn () => StockWriteOff::query()->where('created_at', '>=', $start);

        $count = $month()->count();
        $topReason = $month()->selectRaw('reason, COUNT(*) as total')->groupBy('reason')->orderByDesc('total')->first();

        $stats = [
            Stat::make('Kejadian Bulan Ini', $count)
                ->description('Sejak ' . $start->format('d M Y'))
                ->descriptionIcon('heroicon-m-trash')
                ->color($count > 0 ? 'warning' : 'success'),
        ];

        if (StockWriteOffResource::canSeeValue()) {
            $value = (float) $month()->sum('total_value');
            $stats[] = Stat::make('Nilai Kerugian Bulan Ini', 'Rp ' . number_format($value, 0, ',', '.'))
                ->description('Hanya yang dinilai (harga modal terisi)')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($value > 0 ? 'danger' : 'success');
        }

        $stats[] = Stat::make('Alasan Terbanyak', $topReason ? (StockWriteOff::REASON_LABELS[$topReason->reason] ?? $topReason->reason) : '—')
            ->description($topReason ? "{$topReason->total} kejadian bulan ini" : 'Belum ada bulan ini')
            ->descriptionIcon('heroicon-m-exclamation-triangle')
            ->color('info');

        return $stats;
    }
}
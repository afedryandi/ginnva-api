<?php

namespace App\Filament\Widgets;

use App\Models\Store;
use Filament\Widgets\ChartWidget;

class WarrantyByStoreChart extends ChartWidget
{
    protected static ?string $heading = 'Garansi per Toko';

    protected static ?int $sort = 3;

    /**
     * Sesuai mind map "data statistics" > "Statistics by store".
     * Hanya relevan untuk super_admin (perbandingan ANTAR toko) — admin
     * toko hanya punya 1 toko sendiri sehingga chart perbandingan ini
     * tidak bermakna baginya.
     */
    public static function canView(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    protected function getData(): array
    {
        $stores = Store::query()
            ->withCount('warranties')
            ->orderByDesc('warranties_count')
            ->limit(10)
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Jumlah Garansi',
                    'data' => $stores->pluck('warranties_count')->toArray(),
                    'backgroundColor' => '#3b82f6',
                ],
            ],
            'labels' => $stores->pluck('name')->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
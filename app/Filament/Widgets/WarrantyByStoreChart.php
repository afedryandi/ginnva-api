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
        return auth()->user()?->isFullAccess() ?? false;
    }

    // Palet warna bergantian per bar (bukan satu warna flat) supaya tiap
    // toko gampang dibedakan sekilas mata tanpa harus baca label satu-satu.
    private const PALETTE = [
        '#C8A96E', '#3b82f6', '#10b981', '#f59e0b', '#ef4444',
        '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16', '#6366f1',
    ];

    protected function getData(): array
    {
        $stores = Store::query()
            ->withCount('warranties')
            ->orderByDesc('warranties_count')
            ->limit(10)
            ->get();

        $colors = $stores->values()->map(fn ($store, $i) => self::PALETTE[$i % count(self::PALETTE)])->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Jumlah Garansi',
                    'data' => $stores->pluck('warranties_count')->toArray(),
                    'backgroundColor' => $colors,
                    'borderRadius' => 6,
                ],
            ],
            'labels' => $stores->pluck('name')->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                    'grid' => ['color' => 'rgba(148, 163, 184, 0.12)'],
                ],
                'x' => [
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }
}
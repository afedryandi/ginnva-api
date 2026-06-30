<?php

namespace App\Filament\Widgets;

use App\Models\Warranty;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class WarrantyTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Tren Pengajuan Garansi (30 Hari Terakhir)';

    protected static ?int $sort = 2;

    /**
     * Sesuai mind map "data statistics" > "Time cycle content statistics"
     * (Start/End Time). Untuk versi pertama dipakai rentang fix 30 hari
     * terakhir; filter rentang custom bisa ditambah belakangan kalau
     * dibutuhkan lewat getFilters().
     *
     * regional_admin (admin toko) hanya lihat tren tokonya sendiri,
     * konsisten dengan scope di WarrantyResource & DashboardStatsWidget.
     */
    protected function getData(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->hasRole('super_admin') ?? false;

        $start = Carbon::now()->subDays(29)->startOfDay();

        $query = Warranty::query()->where('created_at', '>=', $start);

        if (! $isSuperAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        $rows = $query
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $labels = [];
        $data = [];

        for ($date = $start->copy(); $date->lte(Carbon::now()); $date->addDay()) {
            $key = $date->format('Y-m-d');
            $labels[] = $date->format('d M');
            $data[] = (int) ($rows[$key] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Garansi Diajukan',
                    'data' => $data,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => true,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
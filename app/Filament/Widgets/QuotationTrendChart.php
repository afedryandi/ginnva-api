<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\QuotationResource;
use App\Models\Quotation;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class QuotationTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Tren Quotation';

    protected static ?int $sort = 4;

    /**
     * Sama temuan dengan WarrantyTrendChart — sebelumnya tidak ada
     * canView() sama sekali, jadi staff yang menu_access-nya TIDAK
     * mencakup Quotation tetap melihat tren volume quotation masuk di
     * Dashboard, tidak konsisten dengan DashboardStatsWidget yang sudah
     * dibatasi hasMenuAccess() per kartu.
     */
    public static function canView(): bool
    {
        return auth()->user()?->hasMenuAccess(QuotationResource::class) ?? false;
    }

    protected function getFilters(): ?array
    {
        return [
            '6'  => '6 Bulan Terakhir',
            '12' => '12 Bulan Terakhir',
            '24' => '24 Bulan Terakhir',
        ];
    }

    protected function getData(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->isFullAccess() ?? false;

        $months = (int) ($this->filter ?? 12);
        $start = Carbon::now()->subMonths($months - 1)->startOfMonth();

        $query = Quotation::query()->where('created_at', '>=', $start);

        if (! $isSuperAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        $rows = $query
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $labels = [];
        $data = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $key = $month->format('Y-m');
            $labels[] = $month->translatedFormat('M Y');
            $data[] = (int) ($rows[$key] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Quotation Masuk',
                    'data' => $data,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.18)',
                    'pointBackgroundColor' => '#10b981',
                    'pointBorderColor' => '#ffffff',
                    'pointRadius' => 3,
                    'pointHoverRadius' => 5,
                    'borderWidth' => 2,
                    'tension' => 0.4,
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

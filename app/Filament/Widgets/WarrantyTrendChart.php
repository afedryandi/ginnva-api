<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\WarrantyResource;
use App\Models\Warranty;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class WarrantyTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Tren Pengajuan Garansi';

    protected static ?int $sort = 2;

    /**
     * SEBELUMNYA tidak ada canView() sama sekali di sini — beda dari
     * DashboardStatsWidget yang SUDAH dibatasi hasMenuAccess() untuk tiap
     * kartu (lihat komentar di sana: dulu widget itu satu-satunya tempat
     * yang menampilkan data lintas modul tanpa peduli menu_access staff).
     * Chart ini luput dari perbaikan yang sama — staff yang menu_access-
     * nya TIDAK mencakup Garansi tetap melihat tren volume pengajuan
     * garansi (data yang seharusnya tidak boleh dia akses) di Dashboard.
     */
    public static function canView(): bool
    {
        return auth()->user()?->hasMenuAccess(WarrantyResource::class) ?? false;
    }

    /**
     * store_manager (admin toko) hanya lihat tren tokonya sendiri,
     * konsisten dengan scope di WarrantyResource & DashboardStatsWidget.
     */
    protected function getFilters(): ?array
    {
        return [
            '7'  => '7 Hari Terakhir',
            '30' => '30 Hari Terakhir',
            '90' => '90 Hari Terakhir',
        ];
    }

    protected function getData(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->isFullAccess() ?? false;

        $days = (int) ($this->filter ?? 30);
        $start = Carbon::now()->subDays($days - 1)->startOfDay();

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
                    'borderColor' => '#C8A96E',
                    'backgroundColor' => 'rgba(200, 169, 110, 0.18)',
                    'pointBackgroundColor' => '#C8A96E',
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
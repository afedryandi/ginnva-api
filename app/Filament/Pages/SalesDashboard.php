<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BookingRevenueByCategoryChart;
use App\Filament\Widgets\BookingRevenueStatsWidget;
use Filament\Pages\Page;

/**
 * "Dashboard Penjualan" — diminta 2026-09-08 (permintaan susulan),
 * referensi dashboard.majoo.id/sales-dashboard. BookingRevenueStatsWidget
 * & BookingRevenueByCategoryChart SEBELUMNYA cuma tampil di Dashboard
 * utama /admin (campur dengan widget operasional modul lain) — dipajang
 * lagi di sini (BUKAN dipindah, widget yang sama tetap tampil di
 * Dashboard utama juga) supaya tab Penjualan py punya "home" sendiri
 * yang fokus cuma ke pendapatan, sama pola Page terpisah dengan
 * InventoryDashboard (bukan extend Filament\Pages\Dashboard, widget
 * di-render manual lewat <x-filament-widgets::widgets>).
 */
class SalesDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Dashboard Penjualan';

    protected static ?string $title = 'Dashboard Penjualan';

    protected static string $view = 'filament.pages.sales-dashboard';

    public static function canAccess(): bool
    {
        return BookingRevenueStatsWidget::canView();
    }

    public function getWidgets(): array
    {
        return [
            BookingRevenueStatsWidget::class,
            BookingRevenueByCategoryChart::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}

<?php

namespace App\Filament\Pages;

use App\Filament\InventoryWidgets\InventoryStatsOverview;
use App\Filament\InventoryWidgets\MaterialsNeedingAttentionWidget;
use App\Filament\InventoryWidgets\ProblemAssetsWidget;
use Filament\Pages\Page;

/**
 * Dashboard TERPISAH dari Dashboard utama /admin — Page biasa dengan view
 * sendiri (SAMA POLA dengan SendNotification.php), BUKAN extend
 * Filament\Pages\Dashboard. Extend Dashboard langsung sempat dicoba tapi
 * ternyata merusak resolusi route (Dashboard bawaan Filament punya
 * penanganan slug/route khusus yang tidak otomatis menyesuaikan untuk
 * subclass) — widget di-render manual lewat komponen
 * <x-filament-widgets::widgets> di view, bukan lewat getWidgets() milik
 * Dashboard.
 */
class InventoryDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Inventaris';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Dashboard Inventaris';

    protected static ?string $title = 'Dashboard Inventaris';

    protected static string $view = 'filament.pages.inventory-dashboard';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public function getWidgets(): array
    {
        return [
            InventoryStatsOverview::class,
            MaterialsNeedingAttentionWidget::class,
            ProblemAssetsWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}

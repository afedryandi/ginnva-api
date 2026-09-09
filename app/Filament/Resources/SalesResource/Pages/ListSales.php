<?php

namespace App\Filament\Resources\SalesResource\Pages;

use App\Filament\Resources\SalesResource;
use App\Filament\Widgets\SalesDetailStatsWidget;
use Filament\Resources\Pages\ListRecords;

// Sengaja tidak override getHeaderActions() — tidak ada CreateAction,
// resource ini murni laporan read-only (lihat komentar di SalesResource).
class ListSales extends ListRecords
{
    protected static string $resource = SalesResource::class;

    // Stat card (diminta 2026-09-09, analog Detail Penjualan Majoo) --
    // lihat catatan keterbatasan di SalesDetailStatsWidget.
    protected function getHeaderWidgets(): array
    {
        return [
            SalesDetailStatsWidget::class,
        ];
    }
}

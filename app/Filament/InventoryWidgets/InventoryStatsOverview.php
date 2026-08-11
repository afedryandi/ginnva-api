<?php

namespace App\Filament\InventoryWidgets;

use App\Filament\Resources\AssetResource;
use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\RawMaterialResource;
use App\Models\Asset;
use App\Models\InventoryItem;
use App\Models\RawMaterial;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * SENGAJA ditaruh di luar app/Filament/Widgets (yang di-auto-discover
 * panel-wide di AdminPanelProvider) — supaya widget ini TIDAK ikut
 * nongol di Dashboard utama /admin, cuma dipasang manual di
 * InventoryDashboard::getWidgets().
 */
class InventoryStatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $materialValue = RawMaterial::query()
            ->whereNotNull('unit_cost')
            ->get(['current_stock', 'unit_cost'])
            ->sum(fn (RawMaterial $m) => (float) $m->current_stock * (float) $m->unit_cost);

        $assetValue = Asset::query()
            ->whereNotNull('purchase_cost')
            ->where('status', '!=', 'dijual')
            ->sum('purchase_cost');

        $lowStockCount = RawMaterial::query()
            ->whereNotNull('reorder_point')
            ->whereColumn('current_stock', '<=', 'reorder_point')
            ->count();

        $nearExpiryCount = RawMaterial::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(30))
            ->count();

        $problemAssetCount = Asset::query()
            ->whereIn('status', ['rusak', 'diperbaiki', 'hilang'])
            ->count();

        $readyStockCount = InventoryItem::query()
            ->where('status', 'in_stock')
            ->count();

        return [
            Stat::make('Produk PPF/WF Ready Stock', $readyStockCount)
                ->description('Unit fisik berstatus "Ada Stok"')
                ->descriptionIcon('heroicon-m-cube')
                ->color('success')
                ->url(InventoryItemResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'in_stock']]])),

            Stat::make('Nilai Stok Bahan Baku', 'Rp ' . number_format($materialValue, 0, ',', '.'))
                ->description('Total current stock × harga per satuan')
                ->descriptionIcon('heroicon-m-beaker')
                ->color('info')
                ->url(RawMaterialResource::getUrl('index')),

            Stat::make('Nilai Aset', 'Rp ' . number_format($assetValue, 0, ',', '.'))
                ->description('Total harga beli aset yang belum dijual')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('info')
                ->url(AssetResource::getUrl('index')),

            Stat::make('Bahan Baku Menipis', $lowStockCount)
                ->description('Stok ≤ ambang stok menipis')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($lowStockCount > 0 ? 'danger' : 'success')
                ->url(RawMaterialResource::getUrl('index')),

            Stat::make('Bahan Baku Kedaluwarsa/Mendekati', $nearExpiryCount)
                ->description('Kedaluwarsa dalam 30 hari ke depan atau sudah lewat')
                ->descriptionIcon('heroicon-m-clock')
                ->color($nearExpiryCount > 0 ? 'danger' : 'success')
                ->url(RawMaterialResource::getUrl('index')),

            Stat::make('Aset Bermasalah', $problemAssetCount)
                ->description('Status Rusak/Diperbaiki/Hilang')
                ->descriptionIcon('heroicon-m-wrench')
                ->color($problemAssetCount > 0 ? 'warning' : 'success')
                ->url(AssetResource::getUrl('index')),
        ];
    }
}

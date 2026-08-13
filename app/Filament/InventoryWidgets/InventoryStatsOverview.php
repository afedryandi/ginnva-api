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
    // Widget di Filament v3 default-nya "lazy" — isinya diambil lewat
    // request Livewire terpisah SETELAH halaman utama tampil. Request
    // susulan otomatis itu yang diduga jadi biang 419 (lihat percakapan
    // debug) — dimatikan supaya widget langsung ikut di HTML awal, tanpa
    // request tambahan sama sekali.
    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;

        // Bahan Baku & Produk PPF/WF memang company-wide (gudang pusat,
        // tidak ber-scope ke 1 toko) — TIDAK di-filter store_id, beda
        // dari Aset yang sekarang per-toko (lihat AssetResource).
        $materialValue = RawMaterial::query()
            ->whereNotNull('unit_cost')
            ->get(['current_stock', 'unit_cost'])
            ->sum(fn (RawMaterial $m) => (float) $m->current_stock * (float) $m->unit_cost);

        $assetValueQuery = Asset::query()
            ->whereNotNull('purchase_cost')
            ->where('status', '!=', 'dijual');
        if (! $isFullAccess) {
            $assetValueQuery->where('store_id', $user?->store_id);
        }
        $assetValue = $assetValueQuery->sum('purchase_cost');

        $lowStockCount = RawMaterial::query()
            ->whereNotNull('reorder_point')
            ->whereColumn('current_stock', '<=', 'reorder_point')
            ->count();

        $nearExpiryCount = RawMaterial::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(30))
            ->count();

        $problemAssetQuery = Asset::query()->whereIn('status', ['rusak', 'diperbaiki', 'hilang']);
        if (! $isFullAccess) {
            $problemAssetQuery->where('store_id', $user?->store_id);
        }
        $problemAssetCount = $problemAssetQuery->count();

        $readyStockCount = InventoryItem::query()
            ->where('status', 'in_stock')
            ->count();

        // Kartu cuma ditampilkan kalau user punya akses ke menu terkait —
        // full-access selalu lihat semua. Tanpa ini, staff yang dicentang
        // akses "Dashboard Inventaris" tapi TIDAK dicentang akses ke
        // salah satu menu (mis. Aset) akan tetap lihat kartunya, lalu
        // begitu diklik kena 403 karena sebenarnya tidak boleh buka
        // menunya.
        $canViewInventoryItems = $isFullAccess || ($user?->hasMenuAccess(InventoryItemResource::class) ?? false);
        $canViewRawMaterials = $isFullAccess || ($user?->hasMenuAccess(RawMaterialResource::class) ?? false);
        $canViewAssets = $isFullAccess || ($user?->hasMenuAccess(AssetResource::class) ?? false);

        $stats = [];

        if ($canViewInventoryItems) {
            $stats[] = Stat::make('Produk PPF/WF Ready Stock', $readyStockCount)
                ->description('Unit fisik berstatus "Ada Stok"')
                ->descriptionIcon('heroicon-m-cube')
                ->color('success')
                ->url(InventoryItemResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'in_stock']]]));
        }

        if ($canViewRawMaterials) {
            $stats[] = Stat::make('Nilai Stok Bahan Baku', 'Rp ' . number_format($materialValue, 0, ',', '.'))
                ->description('Total current stock × harga per satuan')
                ->descriptionIcon('heroicon-m-beaker')
                ->color('info')
                ->url(RawMaterialResource::getUrl('index'));
        }

        if ($canViewAssets) {
            $stats[] = Stat::make('Nilai Aset', 'Rp ' . number_format($assetValue, 0, ',', '.'))
                ->description($isFullAccess ? 'Total harga beli aset yang belum dijual' : 'Total harga beli aset toko ini yang belum dijual')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('info')
                ->url(AssetResource::getUrl('index'));
        }

        if ($canViewRawMaterials) {
            $stats[] = Stat::make('Bahan Baku Menipis', $lowStockCount)
                ->description('Stok ≤ ambang stok menipis')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($lowStockCount > 0 ? 'danger' : 'success')
                ->url(RawMaterialResource::getUrl('index'));

            $stats[] = Stat::make('Bahan Baku Kedaluwarsa/Mendekati', $nearExpiryCount)
                ->description('Kedaluwarsa dalam 30 hari ke depan atau sudah lewat')
                ->descriptionIcon('heroicon-m-clock')
                ->color($nearExpiryCount > 0 ? 'danger' : 'success')
                ->url(RawMaterialResource::getUrl('index'));
        }

        if ($canViewAssets) {
            $stats[] = Stat::make('Aset Bermasalah', $problemAssetCount)
                ->description($isFullAccess ? 'Status Rusak/Diperbaiki/Hilang' : 'Status Rusak/Diperbaiki/Hilang di toko ini')
                ->descriptionIcon('heroicon-m-wrench')
                ->color($problemAssetCount > 0 ? 'warning' : 'success')
                ->url(AssetResource::getUrl('index'));
        }

        return $stats;
    }
}

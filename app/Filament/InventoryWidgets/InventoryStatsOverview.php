<?php

namespace App\Filament\InventoryWidgets;

use App\Filament\Resources\AssetResource;
use App\Filament\Resources\ConsumableItemResource;
use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\RawMaterialResource;
use App\Models\Asset;
use App\Models\ConsumableItem;
use App\Models\InventoryItem;
use App\Models\RawMaterial;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

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

    /**
     * Tingkat urgensi bertahap, bukan cuma merah/hijau biner — 1 item
     * menipis dan 50 item menipis dulunya tampil sama-sama "danger".
     */
    private function severityColor(int $count): string
    {
        return match (true) {
            $count <= 0 => 'success',
            $count <= 4 => 'warning',
            default => 'danger',
        };
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;

        // Bahan Baku & Produk PPF/WF memang company-wide (gudang pusat,
        // tidak ber-scope ke 1 toko) — TIDAK di-filter store_id, beda
        // dari Aset yang sekarang per-toko (lihat AssetResource).
        //
        // Dihitung PER BATCH (quantity batch × unit_cost batch itu
        // sendiri), BUKAN current_stock × 1 harga rata-rata di
        // raw_materials — supaya kalau harga beli berubah antar
        // pembelian, valuasi tetap akurat mengikuti harga masing-masing
        // batch yang benar-benar masih tersisa (lihat migration
        // add_unit_cost_to_raw_material_batches).
        $materialValue = (float) DB::table('raw_material_batches')
            ->where('quantity', '>', 0)
            ->whereNotNull('unit_cost')
            ->sum(DB::raw('quantity * unit_cost'));

        // Supaya "Nilai Stok" tidak diam-diam meremehkan valuasi — dihitung
        // juga berapa bahan yang punya batch MASIH ADA stoknya tapi belum
        // diisi harga satuannya (jadi tidak ikut ke nilai di atas),
        // ditampilkan sebagai peringatan terpisah.
        $materialMissingCostCount = RawMaterial::query()
            ->whereHas('batches', fn ($q) => $q->where('quantity', '>', 0)->whereNull('unit_cost'))
            ->count();

        $assetValueQuery = Asset::query()
            ->whereNotNull('purchase_cost')
            ->where('status', '!=', 'dijual');
        if (! $isFullAccess) {
            $assetValueQuery->where('store_id', $user?->store_id);
        }
        $assetValue = $assetValueQuery->sum('purchase_cost');

        // Closure dipakai di semua count "perlu perhatian" di bawah —
        // baris yang sudah "Tandai Ditinjau" (lewat widget tabel) DAN
        // belum ada perubahan lagi sejak itu tidak ikut dihitung, supaya
        // angka di kartu ini konsisten dengan apa yang tampil di tabel.
        $notAcknowledged = fn ($q) => $q->where(fn ($q2) => $q2->whereNull('reviewed_at')->orWhereColumn('reviewed_at', '<', 'updated_at'));

        $lowStockCount = RawMaterial::query()
            ->whereNotNull('reorder_point')
            ->whereColumn('current_stock', '<=', 'reorder_point')
            ->tap($notAcknowledged)
            ->count();

        $materialDeadStockCount = RawMaterial::query()
            ->where('current_stock', '>', 0)
            ->where('updated_at', '<', now()->subDays(RawMaterial::DEAD_STOCK_DAYS))
            ->tap($notAcknowledged)
            ->count();

        // Barang Habis Pakai company-wide, sama seperti Bahan Baku (bukan per-toko).
        $consumableValue = (float) ConsumableItem::query()
            ->whereNotNull('unit_cost')
            ->sum(DB::raw('current_stock * unit_cost'));

        $consumableMissingCostCount = ConsumableItem::query()->whereNull('unit_cost')->count();

        $consumableLowStockCount = ConsumableItem::query()
            ->whereNotNull('reorder_point')
            ->whereColumn('current_stock', '<=', 'reorder_point')
            ->tap($notAcknowledged)
            ->count();

        $consumableDeadStockCount = ConsumableItem::query()
            ->where('current_stock', '>', 0)
            ->where('updated_at', '<', now()->subDays(ConsumableItem::DEAD_STOCK_DAYS))
            ->tap($notAcknowledged)
            ->count();

        // Dihitung dari batch yang MASIH ADA stoknya (bukan kolom
        // expiry_date induk yang cuma snapshot pendaftaran) — konsisten
        // dengan RawMaterial::isNearExpiry()/isExpired() dan filter yang
        // sama di RawMaterialResource.
        $nearExpiryCount = RawMaterial::query()
            ->whereHas('batches', fn ($q) => $q->where('quantity', '>', 0)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays(30)))
            ->tap($notAcknowledged)
            ->count();

        $problemAssetQuery = Asset::query()->whereIn('status', ['rusak', 'diperbaiki', 'hilang']);
        if (! $isFullAccess) {
            $problemAssetQuery->where('store_id', $user?->store_id);
        }
        $problemAssetCount = $problemAssetQuery->tap($notAcknowledged)->count();

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
        $canViewConsumables = $isFullAccess || ($user?->hasMenuAccess(ConsumableItemResource::class) ?? false);

        $stats = [];

        // Rollup lintas-kategori supaya manager tidak perlu scroll & jumlah
        // manual satu-satu dari tiap kartu di bawah — ini yang dilihat
        // pertama kali untuk tahu "hari ini ada berapa yang perlu ditindak".
        $totalAttentionCount = ($canViewRawMaterials ? $lowStockCount + $nearExpiryCount + $materialDeadStockCount : 0)
            + ($canViewConsumables ? $consumableLowStockCount + $consumableDeadStockCount : 0)
            + ($canViewAssets ? $problemAssetCount : 0);

        if ($canViewRawMaterials || $canViewConsumables || $canViewAssets) {
            $stats[] = Stat::make('Perlu Perhatian Hari Ini', $totalAttentionCount)
                ->description($totalAttentionCount > 0
                    ? 'Total bahan/barang menipis, kedaluwarsa, tidak bergerak + aset bermasalah'
                    : 'Semua kategori dalam kondisi baik')
                ->descriptionIcon($totalAttentionCount > 0 ? 'heroicon-m-bell-alert' : 'heroicon-m-check-circle')
                ->color($this->severityColor($totalAttentionCount));
        }

        if ($canViewInventoryItems) {
            $stats[] = Stat::make('Produk PPF/WF Ready Stock', $readyStockCount)
                ->description('Unit fisik berstatus "Ada Stok"')
                ->descriptionIcon('heroicon-m-cube')
                ->color('success')
                ->url(InventoryItemResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'in_stock']]]));
        }

        if ($canViewRawMaterials) {
            $stats[] = Stat::make('Nilai Stok Bahan Baku', 'Rp ' . number_format($materialValue, 0, ',', '.'))
                ->description(
                    'Seluruh toko · Dihitung per batch (kuantitas × harga batch)'
                    . ($materialMissingCostCount > 0 ? " ({$materialMissingCostCount} bahan ada batch belum ada harga, belum ikut dihitung)" : '')
                )
                ->descriptionIcon('heroicon-m-beaker')
                ->color('info')
                ->url(RawMaterialResource::getUrl('index'));
        }

        if ($canViewConsumables) {
            $stats[] = Stat::make('Nilai Stok Barang Habis Pakai', 'Rp ' . number_format($consumableValue, 0, ',', '.'))
                ->description(
                    'Seluruh toko · Total current stock × harga per satuan'
                    . ($consumableMissingCostCount > 0 ? " ({$consumableMissingCostCount} item belum ada harga, belum ikut dihitung)" : '')
                )
                ->descriptionIcon('heroicon-m-archive-box')
                ->color('info')
                ->url(ConsumableItemResource::getUrl('index'));
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
                ->description('Seluruh toko · Stok ≤ ambang stok menipis')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($this->severityColor($lowStockCount))
                ->url(RawMaterialResource::getUrl('index', ['tableFilters' => ['low_stock' => ['isActive' => true]]]));

            $stats[] = Stat::make('Bahan Baku Kedaluwarsa/Mendekati', $nearExpiryCount)
                ->description('Seluruh toko · Kedaluwarsa dalam 30 hari ke depan atau sudah lewat')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($this->severityColor($nearExpiryCount))
                ->url(RawMaterialResource::getUrl('index', ['tableFilters' => ['near_expiry' => ['isActive' => true]]]));

            $stats[] = Stat::make('Bahan Baku Tidak Bergerak', $materialDeadStockCount)
                ->description('Seluruh toko · Ada stok, tidak ada pergerakan ' . RawMaterial::DEAD_STOCK_DAYS . '+ hari')
                ->descriptionIcon('heroicon-m-clock')
                ->color($this->severityColor($materialDeadStockCount))
                ->url(RawMaterialResource::getUrl('index', ['tableFilters' => ['dead_stock' => ['isActive' => true]]]));
        }

        if ($canViewConsumables) {
            $stats[] = Stat::make('Barang Habis Pakai Menipis', $consumableLowStockCount)
                ->description('Seluruh toko · Stok ≤ ambang stok menipis')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($this->severityColor($consumableLowStockCount))
                ->url(ConsumableItemResource::getUrl('index', ['tableFilters' => ['low_stock' => ['isActive' => true]]]));

            $stats[] = Stat::make('Barang Habis Pakai Tidak Bergerak', $consumableDeadStockCount)
                ->description('Seluruh toko · Ada stok, tidak ada pergerakan ' . ConsumableItem::DEAD_STOCK_DAYS . '+ hari')
                ->descriptionIcon('heroicon-m-clock')
                ->color($this->severityColor($consumableDeadStockCount))
                ->url(ConsumableItemResource::getUrl('index', ['tableFilters' => ['dead_stock' => ['isActive' => true]]]));
        }

        if ($canViewAssets) {
            $stats[] = Stat::make('Aset Bermasalah', $problemAssetCount)
                ->description($isFullAccess ? 'Status Rusak/Diperbaiki/Hilang' : 'Status Rusak/Diperbaiki/Hilang di toko ini')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($this->severityColor($problemAssetCount))
                ->url(AssetResource::getUrl('index'));
        }

        return $stats;
    }
}
<?php

namespace App\Filament\InventoryWidgets;

use App\Filament\Pages\InventoryDashboard;
use App\Filament\Resources\AssetResource;
use App\Filament\Resources\ConsumableItemResource;
use App\Filament\Resources\RawMaterialResource;
use App\Models\Asset;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Baris "Perlu perhatian" Dashboard Inventaris: total dan satu kartu per kategori (bahan baku, barang habis pakai, aset), masing-masing
 * dengan rincian alasannya dan tautan ke daftar yang sudah terfilter (audit Dashboard Inventaris, 2026-11-02). Kartu aktivitas dan nilai
 * rupiah ada di InventoryActivityOverview dan InventoryValueOverview.
 *
 * SENGAJA ditaruh di luar app/Filament/Widgets (yang di-auto-discover panel-wide di AdminPanelProvider) — supaya widget ini TIDAK ikut
 * nongol di Dashboard utama /admin, cuma dipasang manual di InventoryDashboard::getWidgets().
 */
class InventoryStatsOverview extends BaseWidget
{
    // Widget di Filament v3 default-nya "lazy" — isinya diambil lewat request Livewire terpisah SETELAH halaman utama tampil. Request
    // susulan otomatis itu yang diduga jadi biang 419 — dimatikan supaya widget langsung ikut di HTML awal.
    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Perlu perhatian';

    public static function canView(): bool
    {
        return InventoryDashboard::canSee(RawMaterialResource::class)
            || InventoryDashboard::canSee(ConsumableItemResource::class)
            || InventoryDashboard::canSee(AssetResource::class);
    }

    /** Tingkat urgensi bertahap, bukan cuma merah/hijau biner: 1 item menipis dan 50 item menipis dulunya tampil sama-sama "danger". */
    public static function severityColor(int $count): string
    {
        return match (true) {
            $count <= 0 => 'success',
            $count <= 4 => 'warning',
            default => 'danger',
        };
    }

    /** "2 menipis · 1 tidak bergerak"; kosong bila semuanya 0. */
    private function breakdown(array $parts): string
    {
        return collect($parts)->filter()->map(fn ($count, $label) => "{$count} {$label}")->implode(' · ');
    }

    /** Tautan ke daftar yang terfilter bila hanya satu alasan yang berlaku; selain itu ke daftar penuh. */
    private function listUrl(string $resource, array $reasonFilters): string
    {
        $active = array_keys(array_filter($reasonFilters));

        return count($active) === 1
            ? $resource::getUrl('index', ['tableFilters' => [$active[0] => ['isActive' => true]]])
            : $resource::getUrl('index');
    }

    protected function getStats(): array
    {
        // Tanpa akses ke satu pun kategori, tidak ada kartu sama sekali (bukan kartu "semua baik" yang menyesatkan).
        if (! static::canView()) {
            return [];
        }

        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;

        $canMaterials = InventoryDashboard::canSee(RawMaterialResource::class);
        $canConsumables = InventoryDashboard::canSee(ConsumableItemResource::class);
        $canAssets = InventoryDashboard::canSee(AssetResource::class);

        // Baris yang sedang "Tandai Ditinjau" (dan tinjauannya masih berlaku) tidak ikut dihitung, sama dengan tabelnya: lihat
        // Acknowledgeable::scopeNeedsReview().
        $materialsCount = $canMaterials ? MaterialsNeedingAttentionWidget::needingAttentionQuery()->count() : 0;
        $consumablesCount = $canConsumables ? ConsumablesNeedingAttentionWidget::needingAttentionQuery()->count() : 0;

        $assetQuery = Asset::query()->whereIn('status', ['rusak', 'diperbaiki', 'hilang']);
        if (! $isFullAccess) {
            $assetQuery->where('store_id', $user?->store_id);
        }
        $assetStatusCounts = $canAssets
            ? (clone $assetQuery)->needsReview()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            : collect();
        $assetsCount = (int) $assetStatusCounts->sum();

        $total = $materialsCount + $consumablesCount + $assetsCount;
        $stats = [];

        $stats[] = Stat::make('Perlu Perhatian Hari Ini', $total)
            ->description($total > 0
                ? collect([
                    $materialsCount > 0 ? "{$materialsCount} bahan" : null,
                    $consumablesCount > 0 ? "{$consumablesCount} barang" : null,
                    $assetsCount > 0 ? "{$assetsCount} aset" : null,
                ])->filter()->implode(' · ')
                : 'Semua kategori dalam kondisi baik')
            ->descriptionIcon($total > 0 ? 'heroicon-m-bell-alert' : 'heroicon-m-check-circle')
            ->color(self::severityColor($total));

        if ($canMaterials) {
            $low = RawMaterial::query()->whereNotNull('reorder_point')->whereColumn('current_stock', '<=', 'reorder_point')->needsReview()->count();
            // Dihitung dari batch yang MASIH ADA stoknya (bukan kolom expiry_date induk yang cuma snapshot pendaftaran).
            $near = RawMaterial::query()
                ->whereHas('batches', fn ($q) => $q->where('quantity', '>', 0)->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', now()->addDays(30)))
                ->needsReview()
                ->count();
            $dead = RawMaterial::query()
                ->deadStock()
                ->needsReview()
                ->count();

            $stats[] = Stat::make('Bahan Baku Perlu Perhatian', $materialsCount)
                ->description($this->breakdown(['menipis' => $low, 'kedaluwarsa/mendekati' => $near, 'tidak bergerak' => $dead]) ?: 'Semua bahan baku dalam kondisi baik')
                ->descriptionIcon($materialsCount > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color(self::severityColor($materialsCount))
                ->url($this->listUrl(RawMaterialResource::class, ['low_stock' => $low, 'near_expiry' => $near, 'dead_stock' => $dead]));
        }

        if ($canConsumables) {
            $low = ConsumableItem::query()->whereNotNull('reorder_point')->whereColumn('current_stock', '<=', 'reorder_point')->needsReview()->count();
            $dead = ConsumableItem::query()
                ->deadStock()
                ->needsReview()
                ->count();

            $stats[] = Stat::make('Barang Habis Pakai Perlu Perhatian', $consumablesCount)
                ->description($this->breakdown(['menipis' => $low, 'tidak bergerak' => $dead]) ?: 'Semua barang habis pakai dalam kondisi baik')
                ->descriptionIcon($consumablesCount > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color(self::severityColor($consumablesCount))
                ->url($this->listUrl(ConsumableItemResource::class, ['low_stock' => $low, 'dead_stock' => $dead]));
        }

        if ($canAssets) {
            $firstStatus = collect(['rusak', 'hilang', 'diperbaiki'])->first(fn ($status) => ($assetStatusCounts[$status] ?? 0) > 0);

            $stats[] = Stat::make('Aset Bermasalah', $assetsCount)
                ->description(($this->breakdown([
                    'rusak' => (int) ($assetStatusCounts['rusak'] ?? 0),
                    'hilang' => (int) ($assetStatusCounts['hilang'] ?? 0),
                    'diperbaiki' => (int) ($assetStatusCounts['diperbaiki'] ?? 0),
                ]) ?: 'Tidak ada aset bermasalah') . ($isFullAccess ? '' : ' · toko ini'))
                ->descriptionIcon($assetsCount > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color(self::severityColor($assetsCount))
                ->url($firstStatus
                    ? AssetResource::getUrl('index', ['tableFilters' => ['status' => ['value' => $firstStatus]]])
                    : AssetResource::getUrl('index'));
        }

        return $stats;
    }
}

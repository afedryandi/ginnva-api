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
use Illuminate\Support\Facades\DB;

/**
 * Baris "Nilai persediaan" Dashboard Inventaris: nilai rupiah stok bahan baku, barang habis pakai, dan aset. Hanya untuk akses penuh atau
 * pemegang izin "Lihat" pada menu Dashboard Inventaris (lihat InventoryDashboard::canSeeValues()), dan hanya kategori yang menunya
 * boleh dibuka akun itu.
 */
class InventoryValueOverview extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Nilai persediaan';

    public static function canView(): bool
    {
        return InventoryDashboard::canSeeValues()
            && (InventoryDashboard::canSee(RawMaterialResource::class)
                || InventoryDashboard::canSee(ConsumableItemResource::class)
                || InventoryDashboard::canSee(AssetResource::class));
    }

    protected function getStats(): array
    {
        if (! InventoryDashboard::canSeeValues()) {
            return [];
        }

        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;
        $stats = [];

        // Bahan baku dan barang habis pakai memang company-wide (gudang pusat, tidak ber-scope ke 1 toko), beda dari aset yang per toko.
        if (InventoryDashboard::canSee(RawMaterialResource::class)) {
            // Dihitung PER BATCH (quantity batch x unit_cost batch itu), bukan current_stock x 1 harga rata-rata, supaya valuasi mengikuti
            // harga tiap batch yang benar-benar masih tersisa.
            $value = 0.0;
            $missing = 0;
            foreach (RawMaterial::query()->where('current_stock', '>', 0)->with('batches')->get() as $material) {
                $value += $material->stockValue();
                $missing += $material->hasUnpricedStock() ? 1 : 0;
            }

            $stats[] = Stat::make('Nilai Stok Bahan Baku', 'Rp ' . number_format($value, 0, ',', '.'))
                ->description('Seluruh toko · sisa batch (FIFO) × harga batch'
                    . ($missing > 0 ? " ({$missing} bahan ada stok yang belum ada harganya, dinilai Rp0)" : ''))
                ->descriptionIcon('heroicon-m-beaker')
                ->color('info')
                ->url(RawMaterialResource::getUrl('index'));
        }

        if (InventoryDashboard::canSee(ConsumableItemResource::class)) {
            $value = (float) ConsumableItem::query()->whereNotNull('unit_cost')->sum(DB::raw('current_stock * unit_cost'));
            // Hanya yang masih ada stoknya; item kosong tanpa harga tidak memengaruhi nilai stok.
            $missing = ConsumableItem::query()->where('current_stock', '>', 0)->whereNull('unit_cost')->count();

            $stats[] = Stat::make('Nilai Stok Barang Habis Pakai', 'Rp ' . number_format($value, 0, ',', '.'))
                ->description('Seluruh toko · stok × harga per satuan'
                    . ($missing > 0 ? " ({$missing} item belum ada harga, belum ikut dihitung)" : ''))
                ->descriptionIcon('heroicon-m-archive-box')
                ->color('info')
                ->url(ConsumableItemResource::getUrl('index'));
        }

        if (InventoryDashboard::canSee(AssetResource::class)) {
            $query = Asset::query()->whereNotNull('purchase_cost')->where('status', '!=', 'dijual');
            if (! $isFullAccess) {
                $query->where('store_id', $user?->store_id);
            }

            $stats[] = Stat::make('Nilai Aset', 'Rp ' . number_format((float) $query->sum('purchase_cost'), 0, ',', '.'))
                ->description($isFullAccess ? 'Harga beli aset yang belum dijual' : 'Harga beli aset toko ini yang belum dijual')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('info')
                ->url(AssetResource::getUrl('index'));
        }

        return $stats;
    }
}

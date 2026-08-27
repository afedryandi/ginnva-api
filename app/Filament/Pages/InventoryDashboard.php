<?php

namespace App\Filament\Pages;

use App\Filament\InventoryWidgets\ConsumablesNeedingAttentionWidget;
use App\Filament\InventoryWidgets\InventoryStatsOverview;
use App\Filament\InventoryWidgets\MaterialsNeedingAttentionWidget;
use App\Filament\InventoryWidgets\ProblemAssetsWidget;
use App\Models\Asset;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
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

    /**
     * Tabel "perlu perhatian" diurut yang PALING BANYAK row-nya duluan —
     * supaya kategori paling mendesak tidak tenggelam di bawah kategori
     * yang kebetulan kosong. Stat overview selalu di atas (ringkasan),
     * urutan 3 tabel di bawahnya baru dinamis.
     */
    public function getWidgets(): array
    {
        // Baris yang sudah "Tandai Ditinjau" (belum ada perubahan lagi
        // sejak itu) tidak ikut dihitung — konsisten dengan angka yang
        // dipakai widget tabelnya sendiri dan kartu statistik.
        $notAcknowledged = fn ($q) => $q->where(fn ($q2) => $q2->whereNull('reviewed_at')->orWhereColumn('reviewed_at', '<', 'updated_at'));

        $materialsCount = RawMaterial::query()->where(function ($q) {
            $q->where(fn ($q2) => $q2->whereNotNull('reorder_point')->whereColumn('current_stock', '<=', 'reorder_point'))
                // Dihitung dari batch yang masih ada stoknya, bukan kolom
                // expiry_date induk — lihat catatan di InventoryStatsOverview.
                ->orWhereHas('batches', fn ($q2) => $q2->where('quantity', '>', 0)
                    ->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '<=', now()->addDays(30)))
                ->orWhere(fn ($q2) => $q2->where('current_stock', '>', 0)
                    ->where('updated_at', '<', now()->subDays(RawMaterial::DEAD_STOCK_DAYS)));
        })->tap($notAcknowledged)->count();

        $consumablesCount = ConsumableItem::query()->where(function ($q) {
            $q->where(fn ($q2) => $q2->whereNotNull('reorder_point')->whereColumn('current_stock', '<=', 'reorder_point'))
                ->orWhere(fn ($q2) => $q2->where('current_stock', '>', 0)
                    ->where('updated_at', '<', now()->subDays(ConsumableItem::DEAD_STOCK_DAYS)));
        })->tap($notAcknowledged)->count();

        $assetsQuery = Asset::query()->whereIn('status', ['rusak', 'diperbaiki', 'hilang']);
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $assetsQuery->where('store_id', auth()->user()?->store_id);
        }
        $assetsCount = $assetsQuery->tap($notAcknowledged)->count();

        $tableWidgets = collect([
            ['widget' => MaterialsNeedingAttentionWidget::class, 'count' => $materialsCount],
            ['widget' => ConsumablesNeedingAttentionWidget::class, 'count' => $consumablesCount],
            ['widget' => ProblemAssetsWidget::class, 'count' => $assetsCount],
        ])->sortByDesc('count')->pluck('widget')->all();

        return [
            InventoryStatsOverview::class,
            ...$tableWidgets,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}

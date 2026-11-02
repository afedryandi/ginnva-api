<?php

namespace App\Filament\Pages;

use App\Filament\InventoryWidgets\ConsumablesNeedingAttentionWidget;
use App\Filament\InventoryWidgets\InventoryActivityOverview;
use App\Filament\InventoryWidgets\InventoryStatsOverview;
use App\Filament\InventoryWidgets\InventoryValueOverview;
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

    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Dashboard Inventaris';

    protected static ?string $title = 'Dashboard Inventaris';

    protected static string $view = 'filament.pages.inventory-dashboard';

    /**
     * Dashboard ini memuat nilai rupiah seluruh stok dan aset serta tombol yang menyembunyikan baris untuk semua orang, jadi tidak
     * terbuka lewat akses bawaan akun baru (menu_access kosong): hanya akses penuh atau staf yang menunya dicentang eksplisit.
     * Kartu nilai rupiah butuh izin "Lihat" pada menu ini (audit Dashboard Inventaris, 2026-11-02).
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    /** Boleh melihat menu barang ini: akses penuh atau menunya dicentang eksplisit (dipakai kartu dan tabel dashboard). */
    public static function canSee(string $resource): bool
    {
        return auth()->user()?->hasExplicitMenuAccess($resource) ?? false;
    }

    /** Boleh melihat kartu nilai rupiah (stok bahan, barang habis pakai, aset): akses penuh atau izin "Lihat" pada menu ini. */
    public static function canSeeValues(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isFullAccess() || $user->hasModuleAction(static::class, 'view', false));
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
        // Query yang SAMA dengan isi tabel masing-masing (satu sumber kebenaran), supaya urutan mengikuti jumlah baris sungguhan.
        $materialsCount = MaterialsNeedingAttentionWidget::needingAttentionQuery()->count();
        $consumablesCount = ConsumablesNeedingAttentionWidget::needingAttentionQuery()->count();

        $assetsQuery = Asset::query()->whereIn('status', ['rusak', 'diperbaiki', 'hilang']);
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $assetsQuery->where('store_id', auth()->user()?->store_id);
        }
        $assetsCount = $assetsQuery->needsReview()->count();

        $tableWidgets = collect([
            ['widget' => MaterialsNeedingAttentionWidget::class, 'count' => $materialsCount],
            ['widget' => ConsumablesNeedingAttentionWidget::class, 'count' => $consumablesCount],
            ['widget' => ProblemAssetsWidget::class, 'count' => $assetsCount],
        ])->sortByDesc('count')->pluck('widget')->all();

        return [
            InventoryStatsOverview::class,
            InventoryActivityOverview::class,
            InventoryValueOverview::class,
            ...$tableWidgets,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
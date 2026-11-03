<?php

namespace App\Filament\InventoryWidgets;

use App\Filament\Pages\InventoryDashboard;
use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\PurchaseRequestResource;
use App\Filament\Resources\StockOpnameResource;
use App\Filament\Resources\StockWriteOffResource;
use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Models\StockOpname;
use App\Models\StockWriteOff;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Baris "Aktivitas stok" Dashboard Inventaris (audit 2026-11-02): sinyal kerja harian di luar barang yang menipis, yaitu permohonan
 * pembelian yang menunggu, stok terbuang bulan ini, kapan stok opname terakhir, dan ready stock produk PPF/WF. Tiap kartu hanya tampil
 * bagi yang punya akses eksplisit ke menunya, dan membuka daftar yang terfilter.
 */
class InventoryActivityOverview extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Aktivitas stok';

    public static function canView(): bool
    {
        return InventoryDashboard::canSee(PurchaseRequestResource::class)
            || InventoryDashboard::canSee(StockWriteOffResource::class)
            || InventoryDashboard::canSee(StockOpnameResource::class)
            || InventoryDashboard::canSee(InventoryItemResource::class);
    }

    protected function getStats(): array
    {
        $stats = [];

        if (InventoryDashboard::canSee(PurchaseRequestResource::class)) {
            $counts = PurchaseRequest::query()->whereIn('status', ['pending', 'approved'])->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            $pending = (int) ($counts['pending'] ?? 0);
            $approved = (int) ($counts['approved'] ?? 0);

            $stats[] = Stat::make('Permohonan Pembelian Menunggu', $pending)
                ->description($approved > 0 ? "{$approved} disetujui, belum diterima" : 'Menunggu persetujuan')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color(InventoryStatsOverview::severityColor($pending))
                ->url(PurchaseRequestResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'pending']]]));
        }

        if (InventoryDashboard::canSee(StockWriteOffResource::class)) {
            $writeOffs = StockWriteOff::query()->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);
            $count = (clone $writeOffs)->count();
            // Nilai rupiah hanya bagi yang boleh melihat nilai.
            $value = InventoryDashboard::canSeeValues() ? (float) (clone $writeOffs)->sum('total_value') : null;

            $stats[] = Stat::make('Stok Terbuang Bulan Ini', $count)
                ->description($value !== null && $count > 0 ? 'Senilai Rp ' . number_format($value, 0, ',', '.') : ($count > 0 ? 'Rusak, kedaluwarsa, atau hilang' : 'Tidak ada pembuangan stok'))
                ->descriptionIcon('heroicon-m-trash')
                ->color($count > 0 ? 'warning' : 'success')
                ->url(StockWriteOffResource::getUrl('index'));
        }

        if (InventoryDashboard::canSee(StockOpnameResource::class)) {
            // Hanya sesi yang sudah diterapkan yang dihitung (draf belum mengubah stok, yang dibuang tidak pernah berlaku).
            $last = StockOpname::query()->where('status', StockOpname::STATUS_APPLIED)->max('opname_date');
            $days = $last ? (int) Carbon::parse($last)->startOfDay()->diffInDays(today()) : null;

            $stats[] = Stat::make('Opname Terakhir', $days === null ? 'Belum pernah' : ($days === 0 ? 'Hari ini' : "{$days} hari lalu"))
                ->description($days === null ? 'Belum ada sesi stok opname' : 'Sesi stok opname paling baru')
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color($days === null || $days > 60 ? 'danger' : ($days > 30 ? 'warning' : 'success'))
                ->url(StockOpnameResource::getUrl('index'));
        }

        if (InventoryDashboard::canSee(InventoryItemResource::class)) {
            $stats[] = Stat::make('Produk PPF/WF Ready Stock', InventoryItem::query()->where('status', 'in_stock')->count())
                ->description('Unit fisik berstatus "Ada Stok"')
                ->descriptionIcon('heroicon-m-cube')
                ->color('success')
                ->url(InventoryItemResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'in_stock']]]));
        }

        return $stats;
    }
}

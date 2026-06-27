<?php

namespace App\Filament\Widgets;

use App\Models\ProductInquiry;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\Warranty;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardStatsWidget extends BaseWidget
{
    /**
     * regional_admin (admin toko) hanya melihat angka Warranty & Quotation
     * milik tokonya sendiri (+ data lama yang store_id-nya masih null),
     * sama persis dengan scope yang dipakai di WarrantyResource &
     * QuotationResource, supaya angka di widget ini selalu cocok dengan
     * apa yang admin toko lihat saat klik ke tabel listing-nya.
     *
     * Inquiry & Total Toko tetap global untuk semua role, karena kedua
     * data ini tidak ber-scope ke toko tertentu.
     */
    protected function getStats(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->hasRole('super_admin') ?? false;

        return [
            Stat::make('Garansi Aktif', $this->countActiveWarranties($user, $isSuperAdmin))
                ->description('Total e-warranty dengan status aktif')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('success'),

            Stat::make('Quotation Baru (7 Hari)', $this->countRecentQuotations($user, $isSuperAdmin))
                ->description('Permintaan quotation masuk minggu ini')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('warning'),

            Stat::make('Inquiry Produk Baru', ProductInquiry::where('status', 'new')->count())
                ->description('Pertanyaan ketersediaan produk belum di-follow up')
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color('info'),

            Stat::make('Toko Aktif', Store::where('is_active', true)->count())
                ->description('Total toko/dealer yang tampil di web publik')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('gray'),
        ];
    }

    protected function countActiveWarranties($user, bool $isSuperAdmin): int
    {
        $query = Warranty::query();

        if (! $isSuperAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        // status dihitung manual di sini (bukan accessor model) karena
        // accessor getStatusAttribute() butuh load tiap record satu-satu,
        // sedangkan untuk hitung total kita cukup bandingkan expiry_date
        // langsung lewat query supaya tetap efisien untuk data banyak.
        return $query->whereDate('expiry_date', '>=', now())->count();
    }

    protected function countRecentQuotations($user, bool $isSuperAdmin): int
    {
        $query = Quotation::query();

        if (! $isSuperAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        return $query->where('created_at', '>=', now()->subDays(7))->count();
    }
}
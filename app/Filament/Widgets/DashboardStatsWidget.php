<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use App\Models\FilmProduct;
use App\Models\PartnershipInquiry;
use App\Models\ProductInquiry;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
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
     * Inquiry, Toko, Produk & User tetap global untuk semua role, karena
     * data ini tidak ber-scope ke toko tertentu.
     */
    protected function getStats(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->hasRole('super_admin') ?? false;

        $stats = [
            Stat::make('Garansi Aktif', $this->countActiveWarranties($user, $isSuperAdmin))
                ->description('QA Certificate approved & belum kedaluwarsa')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('success'),

            Stat::make('Menunggu Review QA', $this->countPendingReview($user, $isSuperAdmin))
                ->description('QA Certificate belum di-approve/reject')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),

            Stat::make('Quotation Baru (7 Hari)', $this->countRecentQuotations($user, $isSuperAdmin))
                ->description('Permintaan quotation masuk minggu ini')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('warning'),

            Stat::make('Inquiry Produk Baru', ProductInquiry::where('status', 'new')->count())
                ->description('Pertanyaan ketersediaan produk belum di-follow up')
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color('info'),

            Stat::make('Kemitraan Baru', PartnershipInquiry::where('status', 'new')->count())
                ->description('Pengajuan dealer/distributor belum ditindaklanjuti')
                ->descriptionIcon('heroicon-m-briefcase')
                ->color('warning'),

            Stat::make('Garansi Hampir Kedaluwarsa', $this->countExpiringWarranties($user, $isSuperAdmin))
                ->description('Garansi berakhir dalam 30 hari ke depan')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),

            Stat::make('Booking Hari Ini', $this->countTodayBookings($user, $isSuperAdmin))
                ->description('Jadwal servis/pemasangan yang masuk hari ini')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('info'),

            Stat::make('Toko Aktif', Store::where('is_active', true)->count())
                ->description('Total toko/dealer yang tampil di web publik')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('gray'),
        ];

        // Stat tambahan khusus super_admin (gambaran nasional, tidak
        // relevan untuk admin toko karena tidak ber-scope ke 1 toko).
        if ($isSuperAdmin) {
            $stats[] = Stat::make('Total User Admin', User::count())
                ->description('Akun admin (super_admin + regional_admin)')
                ->descriptionIcon('heroicon-m-users')
                ->color('gray');

            $stats[] = Stat::make('Produk Film', FilmProduct::where('is_active', true)->count())
                ->description('Produk film aktif yang ditampilkan di website & aplikasi')
                ->descriptionIcon('heroicon-m-film')
                ->color('gray');
        }

        return $stats;
    }

    protected function countActiveWarranties($user, bool $isSuperAdmin): int
    {
        $query = Warranty::query()->where('review_status', 'approved');

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

    protected function countPendingReview($user, bool $isSuperAdmin): int
    {
        $query = Warranty::query()->where('review_status', 'pending_review');

        if (! $isSuperAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        return $query->count();
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

    protected function countExpiringWarranties($user, bool $isSuperAdmin): int
    {
        $query = Warranty::query()
            ->where('review_status', 'approved')
            ->whereDate('expiry_date', '>=', now())
            ->whereDate('expiry_date', '<=', now()->addDays(30));

        if (! $isSuperAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        return $query->count();
    }

    protected function countTodayBookings($user, bool $isSuperAdmin): int
    {
        $query = Booking::query()
            ->whereDate('preferred_date', now()->toDateString());

        if (! $isSuperAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        return $query->count();
    }
}
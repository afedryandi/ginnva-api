<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\FilmProductResource;
use App\Filament\Resources\PartnershipInquiryResource;
use App\Filament\Resources\ProductInquiryResource;
use App\Filament\Resources\QuotationResource;
use App\Filament\Resources\StoreResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\WarrantyResource;
use App\Models\Booking;
use App\Models\FilmProduct;
use App\Models\PartnershipInquiry;
use App\Models\ProductInquiry;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Models\Warranty;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardStatsWidget extends BaseWidget
{
    /**
     * store_manager (admin toko) hanya melihat angka Warranty & Quotation
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
        $isSuperAdmin = $user?->isFullAccess() ?? false;

        // Setiap Stat bisa diklik dan langsung membuka tabel terkait dengan
        // filter yang sudah disetel — supaya dashboard bukan cuma angka
        // mati, tapi jadi pintu masuk cepat ke pekerjaan yang perlu
        // ditindaklanjuti (klik "Menunggu Review QA" -> langsung ke daftar
        // garansi yang perlu di-review, dst).
        $stats = [
            Stat::make('Garansi Aktif', $this->countActiveWarranties($user, $isSuperAdmin))
                ->description('QA Certificate approved & belum kedaluwarsa')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('success')
                ->chart($this->dailyTrend(Warranty::class, 'created_at', $user, $isSuperAdmin))
                ->url(WarrantyResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'active']]])),

            Stat::make('Menunggu Review QA', $this->countPendingReview($user, $isSuperAdmin))
                ->description('QA Certificate belum di-approve/reject')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning')
                ->url(WarrantyResource::getUrl('index', ['tableFilters' => ['review_status' => ['value' => 'pending_review']]])),

            Stat::make('Quotation Baru (7 Hari)', $this->countRecentQuotations($user, $isSuperAdmin))
                ->description('Permintaan quotation masuk minggu ini')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('warning')
                ->chart($this->dailyTrend(Quotation::class, 'created_at', $user, $isSuperAdmin))
                ->url(QuotationResource::getUrl('index')),

            Stat::make('Inquiry Produk Baru', ProductInquiry::where('status', 'new')->count())
                ->description('Pertanyaan ketersediaan produk belum di-follow up')
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color('info')
                ->url(ProductInquiryResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'new']]])),

            Stat::make('Kemitraan Baru', PartnershipInquiry::where('status', 'new')->count())
                ->description('Pengajuan dealer/distributor belum ditindaklanjuti')
                ->descriptionIcon('heroicon-m-briefcase')
                ->color('warning')
                ->url(PartnershipInquiryResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'new']]])),

            Stat::make('Garansi Hampir Kedaluwarsa', $this->countExpiringWarranties($user, $isSuperAdmin))
                ->description('Garansi berakhir dalam 30 hari ke depan')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->url(WarrantyResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'active']]])),

            Stat::make('Booking Hari Ini', $this->countTodayBookings($user, $isSuperAdmin))
                ->description('Jadwal servis/pemasangan yang masuk hari ini')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('info')
                ->chart($this->dailyTrend(Booking::class, 'created_at', $user, $isSuperAdmin))
                ->url(BookingResource::getUrl('index')),

            Stat::make('Toko Aktif', Store::where('is_active', true)->count())
                ->description('Total toko/dealer yang tampil di web publik')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('gray')
                ->url(StoreResource::getUrl('index', ['tableFilters' => ['is_active' => ['value' => '1']]])),
        ];

        // Stat tambahan khusus super_admin (gambaran nasional, tidak
        // relevan untuk admin toko karena tidak ber-scope ke 1 toko).
        if ($isSuperAdmin) {
            $stats[] = Stat::make('Total User Admin', User::count())
                ->description('Semua akun staff/admin')
                ->descriptionIcon('heroicon-m-users')
                ->color('gray')
                ->url(UserResource::getUrl('index'));

            $stats[] = Stat::make('Produk Film', FilmProduct::where('is_active', true)->count())
                ->description('Produk film aktif yang ditampilkan di website & aplikasi')
                ->descriptionIcon('heroicon-m-film')
                ->color('gray')
                ->url(FilmProductResource::getUrl('index'));
        }

        return $stats;
    }

    /**
     * Angka mentah 7 hari terakhir untuk sparkline mini-chart di tiap Stat
     * card — cuma hiasan visual (tren naik/turun sekilas), bukan sumber
     * data utama, jadi query-nya dibuat seringan mungkin (1 groupBy per
     * stat, bukan N+1).
     */
    protected function dailyTrend(string $modelClass, string $dateColumn, $user, bool $isSuperAdmin): array
    {
        $start = now()->subDays(6)->startOfDay();

        $query = $modelClass::query()->where($dateColumn, '>=', $start);

        if (! $isSuperAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        $rows = $query
            ->selectRaw("DATE({$dateColumn}) as date, COUNT(*) as total")
            ->groupBy('date')
            ->pluck('total', 'date');

        $data = [];
        for ($date = $start->copy(); $date->lte(now()); $date->addDay()) {
            $data[] = (int) ($rows[$date->format('Y-m-d')] ?? 0);
        }

        return $data;
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
<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\QuotationResource;
use App\Filament\Resources\WarrantyResource;
use App\Models\Booking;
use App\Models\Quotation;
use App\Models\Warranty;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Pecahan dari DashboardStatsWidget lama — Dashboard sebelumnya menampilkan
 * sampai 11 kartu statistik lintas modul dalam SATU grid datar tanpa
 * pengelompokan sama sekali, padahal sistem ini sudah punya struktur
 * navigationGroups yang jelas (Booking, Marketing/Konten, Karyawan, Master
 * Data, dst). Dipecah per kategori supaya section yang stafnya tidak punya
 * akses menunya otomatis kosong seluruhnya (bukan kartu tercecer di tengah
 * grid), dan makin mudah di-scan sekilas begitu makin banyak modul dapat
 * kartu statistik ke depannya. Kartu di sini semua masuk navigationGroup
 * 'Booking' (Warranty/Quotation/Booking).
 */
class BookingStatsWidget extends BaseWidget
{
    protected ?string $heading = 'Booking';

    protected static ?int $sort = 1;

    /**
     * store_manager (admin toko) hanya melihat angka Warranty & Quotation
     * milik tokonya sendiri (+ data lama yang store_id-nya masih null),
     * sama persis dengan scope yang dipakai di WarrantyResource &
     * QuotationResource, supaya angka di widget ini selalu cocok dengan
     * apa yang admin toko lihat saat klik ke tabel listing-nya.
     */
    protected function getStats(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->isFullAccess() ?? false;

        $stats = [];

        if ($user?->hasMenuAccess(BookingResource::class) ?? false) {
            $stats[] = Stat::make('Booking Hari Ini', $this->countTodayBookings($user, $isSuperAdmin))
                ->description('Jadwal servis/pemasangan yang masuk hari ini')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('info')
                ->chart($this->dailyTrend(Booking::class, 'created_at', $user, $isSuperAdmin))
                ->url(BookingResource::getUrl('index'));
        }

        if ($user?->hasMenuAccess(WarrantyResource::class) ?? false) {
            $stats[] = Stat::make('Garansi Aktif', $this->countActiveWarranties($user, $isSuperAdmin))
                ->description('QA Certificate approved & belum kedaluwarsa')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('success')
                ->chart($this->dailyTrend(Warranty::class, 'created_at', $user, $isSuperAdmin))
                ->url(WarrantyResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'active']]]));

            $stats[] = Stat::make('Menunggu Review QA', $this->countPendingReview($user, $isSuperAdmin))
                ->description('QA Certificate belum di-approve/reject')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning')
                ->url(WarrantyResource::getUrl('index', ['tableFilters' => ['review_status' => ['value' => 'pending_review']]]));

            $stats[] = Stat::make('Garansi Hampir Kedaluwarsa', $this->countExpiringWarranties($user, $isSuperAdmin))
                ->description('Garansi berakhir dalam 30 hari ke depan')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->url(WarrantyResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'active']]]));
        }

        if ($user?->hasMenuAccess(QuotationResource::class) ?? false) {
            $stats[] = Stat::make('Quotation Baru (7 Hari)', $this->countRecentQuotations($user, $isSuperAdmin))
                ->description('Permintaan quotation masuk minggu ini')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('warning')
                ->chart($this->dailyTrend(Quotation::class, 'created_at', $user, $isSuperAdmin))
                ->url(QuotationResource::getUrl('index'));
        }

        return $stats;
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->hasMenuAccess(BookingResource::class) ?? false)
            || ($user?->hasMenuAccess(WarrantyResource::class) ?? false)
            || ($user?->hasMenuAccess(QuotationResource::class) ?? false);
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
        // Cuma 'confirmed' yang dianggap slot yang benar-benar terisi —
        // sama pola dengan Booking::hasScheduleConflict().
        $query = Booking::query()
            ->whereDate('preferred_date', now()->toDateString())
            ->where('status', 'confirmed');

        if (! $isSuperAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        return $query->count();
    }
}

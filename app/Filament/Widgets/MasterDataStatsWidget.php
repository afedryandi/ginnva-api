<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FilmProductResource;
use App\Filament\Resources\StoreResource;
use App\Models\FilmProduct;
use App\Models\Store;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Pecahan dari DashboardStatsWidget lama — lihat catatan lengkap di
 * BookingStatsWidget. Kartu di sini masuk navigationGroup 'Master Data'.
 */
class MasterDataStatsWidget extends BaseWidget
{
    protected ?string $heading = 'Master Data';

    protected static ?int $sort = 7;

    protected function getStats(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->isFullAccess() ?? false;
        $stats = [];

        if ($user?->hasMenuAccess(StoreResource::class) ?? false) {
            $stats[] = Stat::make('Toko Aktif', Store::where('is_active', true)->count())
                ->description('Total toko/dealer yang tampil di web publik')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('gray')
                ->url(StoreResource::getUrl('index', ['tableFilters' => ['is_active' => ['value' => '1']]]));
        }

        // Khusus super_admin — gambaran nasional, tidak relevan untuk admin toko.
        if ($isSuperAdmin) {
            $stats[] = Stat::make('Produk Film', FilmProduct::where('is_active', true)->count())
                ->description('Produk film aktif yang ditampilkan di website & aplikasi')
                ->descriptionIcon('heroicon-m-film')
                ->color('gray')
                ->url(FilmProductResource::getUrl('index'));
        }

        return $stats;
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->hasMenuAccess(StoreResource::class) ?? false)
            || ($user?->isFullAccess() ?? false);
    }
}

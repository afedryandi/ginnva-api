<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PartnershipInquiryResource;
use App\Filament\Resources\ProductInquiryResource;
use App\Models\PartnershipInquiry;
use App\Models\ProductInquiry;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Pecahan dari DashboardStatsWidget lama — lihat catatan lengkap di
 * BookingStatsWidget. Kartu di sini masuk navigationGroup 'Marketing/Konten'.
 */
class MarketingStatsWidget extends BaseWidget
{
    protected ?string $heading = 'Marketing/Konten';

    protected static ?int $sort = 5;

    protected function getStats(): array
    {
        $user = auth()->user();
        $stats = [];

        if ($user?->hasMenuAccess(ProductInquiryResource::class) ?? false) {
            $stats[] = Stat::make('Inquiry Produk Baru', ProductInquiry::where('status', 'new')->count())
                ->description('Pertanyaan ketersediaan produk belum di-follow up')
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color('info')
                ->url(ProductInquiryResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'new']]]));
        }

        if ($user?->hasMenuAccess(PartnershipInquiryResource::class) ?? false) {
            $stats[] = Stat::make('Kemitraan Baru', PartnershipInquiry::where('status', 'new')->count())
                ->description('Pengajuan dealer/distributor belum ditindaklanjuti')
                ->descriptionIcon('heroicon-m-briefcase')
                ->color('warning')
                ->url(PartnershipInquiryResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'new']]]));
        }

        return $stats;
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->hasMenuAccess(ProductInquiryResource::class) ?? false)
            || ($user?->hasMenuAccess(PartnershipInquiryResource::class) ?? false);
    }
}

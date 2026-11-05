<?php

namespace App\Filament\Pages;

use App\Filament\Resources\FilmProductResource;
use App\Filament\Resources\StoreResource;
use App\Filament\Resources\VehicleGlassAreaResource;
use App\Filament\Resources\VehicleResource;
use App\Services\MasterDataQualityService;
use Filament\Pages\Page;

/**
 * "Pusat Master Data" (Master Data Fase 5, 2026-10-21): satu layar kelengkapan master data, baca-saja. Tidak memindahkan master dari menunya;
 * hanya menghitung yang masih kurang dan menautkan ke tempat memperbaikinya.
 */
class MasterDataCenter extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $cluster = \App\Filament\Clusters\MasterDataCluster::class;

    protected static ?string $navigationLabel = 'Pusat Master Data';

    protected static ?string $title = 'Pusat Master Data';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.master-data-center';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false) && $user->hasMenuAccess(static::class);
    }

    /** @return array<string, list<array<string, mixed>>> pemeriksaan dikelompokkan per bagian */
    public function getSections(): array
    {
        return collect(app(MasterDataQualityService::class)->checks())->groupBy('section')->map(fn ($group) => $group->values()->all())->all();
    }

    /** Lencana menu: jumlah pemeriksaan penting yang masih perlu ditindak (dihitung ulang tiap beberapa menit). */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $count = \Illuminate\Support\Facades\Cache::remember('master_data_attention_count', 300, fn () => app(MasterDataQualityService::class)->attentionCount());

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $navigationBadgeColor = 'warning';

    /**
     * Tautan langsung ke baris yang bermasalah: daftar menu terkait dengan filter yang sesuai pemeriksaan sudah menyala. Null bila
     * pemeriksaan tidak punya filter (kembali ke tautan bagian).
     */
    public function linkForCheck(array $check): ?string
    {
        $active = ['is_active' => ['value' => '1']];

        $target = match ($check['key']) {
            'vehicle_no_model' => [VehicleResource::class, $active + ['no_model' => ['isActive' => true]]],
            'vehicle_no_body' => [VehicleResource::class, $active + ['no_body' => ['isActive' => true]]],
            'vehicle_no_area' => [VehicleResource::class, $active + ['no_area' => ['isActive' => true]]],
            'vehicle_size_conflict' => [VehicleResource::class, $active + ['size_conflict' => ['isActive' => true]]],
            'vehicle_inactive_in_use' => [VehicleResource::class, ['is_active' => ['value' => '0']]],
            'area_no_vehicle' => [VehicleGlassAreaResource::class, ['no_vehicle' => ['isActive' => true]]],
            'store_no_code' => [StoreResource::class, ['is_active' => ['value' => '1'], 'no_code' => ['isActive' => true]]],
            'store_no_coordinates' => [StoreResource::class, ['is_active' => ['value' => '1'], 'no_coordinates' => ['isActive' => true]]],
            'store_no_hours' => [StoreResource::class, ['is_active' => ['value' => '1'], 'no_hours' => ['isActive' => true]]],
            'store_no_manager' => [StoreResource::class, ['is_active' => ['value' => '1'], 'no_manager' => ['isActive' => true]]],
            'store_no_capacity' => [StoreResource::class, ['is_active' => ['value' => '1'], 'no_capacity' => ['isActive' => true]]],
            'store_no_phone' => [StoreResource::class, ['is_active' => ['value' => '1'], 'no_phone' => ['isActive' => true]]],
            'product_unpriced' => [FilmProductResource::class, ['price_missing' => ['isActive' => true]]],
            default => null,
        };

        if ($target === null || ! $target[0]::canViewAny()) {
            return null;
        }

        return $target[0]::getUrl('index', ['tableFilters' => $target[1]]);
    }

    /** Tautan tempat memperbaiki tiap bagian. */
    public function linkFor(string $section): ?string
    {
        return match ($section) {
            'Kendaraan' => VehicleResource::canViewAny() ? VehicleResource::getUrl('index') : null,
            'Luas Kaca' => VehicleGlassAreaResource::canViewAny() ? VehicleGlassAreaResource::getUrl('index') : null,
            'Toko' => StoreResource::canViewAny() ? StoreResource::getUrl('index') : null,
            'Produk' => FilmProductResource::canViewAny() ? FilmProductResource::getUrl('index') : null,
            default => null,
        };
    }
}

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

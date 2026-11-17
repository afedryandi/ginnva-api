<?php

namespace App\Filament\Resources\PartnershipInquiryResource\Pages;

use App\Filament\Resources\PartnershipInquiryResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPartnershipInquiries extends ListRecords
{
    protected static string $resource = PartnershipInquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** Tab status di atas daftar, dengan jumlah di tab (mengikuti pembatasan toko pengguna); "Semua" bawaan supaya tidak ada baris tersembunyi. */
    public function getTabs(): array
    {
        $base = fn () => PartnershipInquiryResource::getEloquentQuery()->withoutEagerLoads();

        return [
            'semua' => Tab::make('Semua'),
            'baru' => Tab::make('Baru')
                ->badge($base()->where('status', 'new')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'new')),
            'dihubungi' => Tab::make('Sudah Dihubungi')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'contacted')),
            'deal' => Tab::make('Deal')
                ->badge($base()->where('status', 'deal')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'deal')),
            'ditolak' => Tab::make('Ditolak')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}

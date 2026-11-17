<?php

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPartners extends ListRecords
{
    protected static string $resource = PartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /** Tab status di atas daftar, dengan jumlah di tab (mengikuti pembatasan toko pengguna); "Semua" bawaan supaya tidak ada baris tersembunyi. */
    public function getTabs(): array
    {
        $base = fn () => PartnerResource::getEloquentQuery()->withoutEagerLoads();

        return [
            'semua' => Tab::make('Semua'),
            'aktif' => Tab::make('Aktif')
                ->badge($base()->where('status', 'active')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'active')),
            'nonaktif' => Tab::make('Nonaktif')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'inactive')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}

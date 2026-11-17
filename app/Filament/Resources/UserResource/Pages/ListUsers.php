<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /** Tab status di atas daftar, dengan jumlah di tab (mengikuti pembatasan toko pengguna); "Semua" bawaan supaya tidak ada baris tersembunyi. */
    public function getTabs(): array
    {
        $base = fn () => UserResource::getEloquentQuery()->withoutEagerLoads();

        return [
            'semua' => Tab::make('Semua'),
            'aktif' => Tab::make('Aktif')
                ->badge($base()->where('is_active', true)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true)),
            'nonaktif' => Tab::make('Nonaktif')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
            'kontrak' => Tab::make('Kontrak ≤ 30 hari')
                ->badge($base()->whereNotNull('contract_end_date')->whereDate('contract_end_date', '<=', today()->addDays(30))->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('contract_end_date')->whereDate('contract_end_date', '<=', today()->addDays(30))),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}

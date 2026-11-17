<?php

namespace App\Filament\Resources\TechnicianResource\Pages;

use App\Filament\Resources\TechnicianResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTechnicians extends ListRecords
{
    protected static string $resource = TechnicianResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /** Tab status di atas daftar, dengan jumlah di tab (mengikuti pembatasan toko pengguna); "Semua" bawaan supaya tidak ada baris tersembunyi. */
    public function getTabs(): array
    {
        $base = fn () => TechnicianResource::getEloquentQuery()->withoutEagerLoads();

        return [
            'semua' => Tab::make('Semua'),
            'aktif' => Tab::make('Aktif')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'active')),
            'review' => Tab::make('Menunggu Review')
                ->badge($base()->where('status', 'pending_review')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending_review')),
            'nonaktif' => Tab::make('Nonaktif')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'inactive')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}

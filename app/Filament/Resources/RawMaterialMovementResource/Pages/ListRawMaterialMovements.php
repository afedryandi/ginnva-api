<?php

namespace App\Filament\Resources\RawMaterialMovementResource\Pages;

use App\Filament\Resources\RawMaterialMovementResource;
use App\Filament\Resources\RawMaterialMovementResource\Widgets\RawMaterialMovementStatsOverview;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListRawMaterialMovements extends ListRecords
{
    protected static string $resource = RawMaterialMovementResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            RawMaterialMovementStatsOverview::class,
        ];
    }

    /** Tab status di atas daftar, dengan jumlah di tab (mengikuti pembatasan toko pengguna); "Semua" bawaan supaya tidak ada baris tersembunyi. */
    public function getTabs(): array
    {
        $base = fn () => RawMaterialMovementResource::getEloquentQuery()->withoutEagerLoads();

        return [
            'semua' => Tab::make('Semua'),
            'masuk' => Tab::make('Masuk')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'in')),
            'keluar' => Tab::make('Keluar')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'out')),
            'opname' => Tab::make('Penyesuaian & Koreksi')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('type', ['adjustment', 'correction'])),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}

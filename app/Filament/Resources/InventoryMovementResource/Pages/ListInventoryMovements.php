<?php

namespace App\Filament\Resources\InventoryMovementResource\Pages;

use App\Filament\Resources\InventoryMovementResource;
use App\Filament\Resources\InventoryMovementResource\Widgets\InventoryMovementStatsOverview;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListInventoryMovements extends ListRecords
{
    protected static string $resource = InventoryMovementResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            InventoryMovementStatsOverview::class,
        ];
    }

    /** Tab status di atas daftar, dengan jumlah di tab (mengikuti pembatasan toko pengguna); "Semua" bawaan supaya tidak ada baris tersembunyi. */
    public function getTabs(): array
    {
        $base = fn () => InventoryMovementResource::getEloquentQuery()->withoutEagerLoads();

        return [
            'semua' => Tab::make('Semua'),
            'masuk' => Tab::make('Masuk')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'in')),
            'keluar' => Tab::make('Keluar')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'out')),
            'koreksi' => Tab::make('Koreksi')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'correction')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}

<?php

namespace App\Filament\Resources\StockOpnameResource\Pages;

use App\Filament\Resources\StockOpnameResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListStockOpnames extends ListRecords
{
    protected static string $resource = StockOpnameResource::class;

    protected function getHeaderActions(): array
    {
        // "Buat Sesi Stok Opname" sudah didaftarkan sbg headerActions()
        // di table() resource ini (dekat item Repeater-nya), bukan di
        // sini -- tetap kosong supaya tidak dobel tombol Create bawaan
        // Filament (canCreate() sudah false di Resource).
        return [];
    }

    /** Tab status di atas daftar, dengan jumlah di tab (mengikuti pembatasan toko pengguna); "Semua" bawaan supaya tidak ada baris tersembunyi. */
    public function getTabs(): array
    {
        $base = fn () => StockOpnameResource::getEloquentQuery()->withoutEagerLoads();

        return [
            'semua' => Tab::make('Semua'),
            'draft' => Tab::make('Draf')
                ->badge($base()->where('status', 'draft')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'draft')),
            'diterapkan' => Tab::make('Diterapkan')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'applied')),
            'dibatalkan' => Tab::make('Dibatalkan')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'cancelled')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}

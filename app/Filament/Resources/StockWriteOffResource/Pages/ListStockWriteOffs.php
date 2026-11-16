<?php

namespace App\Filament\Resources\StockWriteOffResource\Pages;

use App\Filament\Resources\StockWriteOffResource;
use App\Filament\Resources\StockWriteOffResource\Widgets\StockWriteOffStatsOverview;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListStockWriteOffs extends ListRecords
{
    protected static string $resource = StockWriteOffResource::class;

    protected function getHeaderWidgets(): array
    {
        return [StockWriteOffStatsOverview::class];
    }

    protected function getHeaderActions(): array
    {
        // Tidak ada Create -- write-off dibuat lewat aksi "Catat Stok
        // Terbuang" di Daftar Bahan Baku / Barang Habis Pakai.
        return [];
    }

    /** Tab status di atas daftar, dengan jumlah di tab (mengikuti pembatasan toko pengguna); "Semua" bawaan supaya tidak ada baris tersembunyi. */
    public function getTabs(): array
    {
        $base = fn () => StockWriteOffResource::getEloquentQuery()->withoutEagerLoads();

        return [
            'semua' => Tab::make('Semua'),
            'aktif' => Tab::make('Aktif')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('cancelled_at')),
            'dibatalkan' => Tab::make('Dibatalkan')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('cancelled_at')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}

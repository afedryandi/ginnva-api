<?php

namespace App\Filament\Resources\StockWriteOffResource\Pages;

use App\Filament\Resources\StockWriteOffResource;
use App\Filament\Resources\StockWriteOffResource\Widgets\StockWriteOffStatsOverview;
use Filament\Resources\Pages\ListRecords;

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
}

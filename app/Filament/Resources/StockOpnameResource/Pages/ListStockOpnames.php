<?php

namespace App\Filament\Resources\StockOpnameResource\Pages;

use App\Filament\Resources\StockOpnameResource;
use Filament\Resources\Pages\ListRecords;

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
}

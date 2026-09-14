<?php

namespace App\Filament\Resources\RollScrapPoolResource\Pages;

use App\Filament\Resources\RollScrapPoolResource;
use Filament\Resources\Pages\ListRecords;

class ListRollScrapPools extends ListRecords
{
    protected static string $resource = RollScrapPoolResource::class;

    // Tidak ada Create -- pool dibuat otomatis lewat aksi "Kumpulkan
    // Sisa" di Produk PPF/WF.
    protected function getHeaderActions(): array
    {
        return [];
    }
}

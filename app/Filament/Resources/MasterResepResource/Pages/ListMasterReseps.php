<?php

namespace App\Filament\Resources\MasterResepResource\Pages;

use App\Filament\Resources\MasterResepResource;
use Filament\Resources\Pages\ListRecords;

class ListMasterReseps extends ListRecords
{
    protected static string $resource = MasterResepResource::class;

    protected static ?string $title = 'Master Resep';

    // Tidak ada tombol Create -- produk dibuat di "Produk Film".
    protected function getHeaderActions(): array
    {
        return [];
    }
}

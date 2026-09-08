<?php

namespace App\Filament\Resources\SalesResource\Pages;

use App\Filament\Resources\SalesResource;
use Filament\Resources\Pages\ListRecords;

// Sengaja tidak override getHeaderActions() — tidak ada CreateAction,
// resource ini murni laporan read-only (lihat komentar di SalesResource).
class ListSales extends ListRecords
{
    protected static string $resource = SalesResource::class;
}

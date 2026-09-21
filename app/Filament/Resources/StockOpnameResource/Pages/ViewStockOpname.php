<?php

namespace App\Filament\Resources\StockOpnameResource\Pages;

use App\Filament\Resources\StockOpnameResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewStockOpname extends ViewRecord
{
    protected static string $resource = StockOpnameResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('opname_number')->label('Nomor'),
            TextEntry::make('store.name')->label('Toko'),
            TextEntry::make('opname_date')->label('Tanggal Opname')->date('d M Y'),
            TextEntry::make('notes')->label('Catatan')->placeholder('—')->columnSpanFull(),
            TextEntry::make('creator.name')->label('Dicatat Oleh')->placeholder('—'),
        ])->columns(2);
    }
}

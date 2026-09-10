<?php

namespace App\Filament\Resources\SpendPromoResource\Pages;

use App\Filament\Resources\SpendPromoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSpendPromos extends ListRecords
{
    protected static string $resource = SpendPromoResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}

<?php

namespace App\Filament\Resources\ScheduledPriceChangeResource\Pages;

use App\Filament\Resources\ScheduledPriceChangeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListScheduledPriceChanges extends ListRecords
{
    protected static string $resource = ScheduledPriceChangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

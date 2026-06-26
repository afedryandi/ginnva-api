<?php

namespace App\Filament\Resources\WarrantyResource\Pages;

use App\Filament\Resources\WarrantyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWarranties extends ListRecords
{
    protected static string $resource = WarrantyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

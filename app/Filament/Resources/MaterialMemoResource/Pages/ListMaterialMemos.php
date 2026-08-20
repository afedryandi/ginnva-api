<?php

namespace App\Filament\Resources\MaterialMemoResource\Pages;

use App\Filament\Resources\MaterialMemoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMaterialMemos extends ListRecords
{
    protected static string $resource = MaterialMemoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

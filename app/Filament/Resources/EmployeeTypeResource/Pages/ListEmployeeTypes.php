<?php

namespace App\Filament\Resources\EmployeeTypeResource\Pages;

use App\Filament\Resources\EmployeeTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeTypes extends ListRecords
{
    protected static string $resource = EmployeeTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

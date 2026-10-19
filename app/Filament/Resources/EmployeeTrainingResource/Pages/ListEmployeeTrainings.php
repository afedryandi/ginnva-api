<?php

namespace App\Filament\Resources\EmployeeTrainingResource\Pages;

use App\Filament\Resources\EmployeeTrainingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeTrainings extends ListRecords
{
    protected static string $resource = EmployeeTrainingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

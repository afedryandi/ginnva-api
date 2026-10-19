<?php

namespace App\Filament\Resources\EmployeeTrainingResource\Pages;

use App\Filament\Resources\EmployeeTrainingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeTraining extends EditRecord
{
    protected static string $resource = EmployeeTrainingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

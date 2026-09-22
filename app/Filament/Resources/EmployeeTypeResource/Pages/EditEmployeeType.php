<?php

namespace App\Filament\Resources\EmployeeTypeResource\Pages;

use App\Filament\Resources\EmployeeTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeType extends EditRecord
{
    protected static string $resource = EmployeeTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

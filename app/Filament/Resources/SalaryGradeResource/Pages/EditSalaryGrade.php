<?php

namespace App\Filament\Resources\SalaryGradeResource\Pages;

use App\Filament\Resources\SalaryGradeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalaryGrade extends EditRecord
{
    protected static string $resource = SalaryGradeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

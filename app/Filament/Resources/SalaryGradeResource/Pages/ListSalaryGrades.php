<?php

namespace App\Filament\Resources\SalaryGradeResource\Pages;

use App\Filament\Resources\SalaryGradeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSalaryGrades extends ListRecords
{
    protected static string $resource = SalaryGradeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

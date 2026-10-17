<?php

namespace App\Filament\Resources\PayrollItemCategoryResource\Pages;

use App\Filament\Resources\PayrollItemCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPayrollItemCategory extends EditRecord
{
    protected static string $resource = PayrollItemCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

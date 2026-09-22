<?php

namespace App\Filament\Resources\RecurringBillTemplateResource\Pages;

use App\Filament\Resources\RecurringBillTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRecurringBillTemplate extends EditRecord
{
    protected static string $resource = RecurringBillTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\RecurringBillTemplateResource\Pages;

use App\Filament\Resources\RecurringBillTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringBillTemplate extends CreateRecord
{
    protected static string $resource = RecurringBillTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}

<?php

namespace App\Filament\Resources\SpendPromoResource\Pages;

use App\Filament\Resources\SpendPromoResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSpendPromo extends CreateRecord
{
    protected static string $resource = SpendPromoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}

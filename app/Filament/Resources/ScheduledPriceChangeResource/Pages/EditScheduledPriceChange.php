<?php

namespace App\Filament\Resources\ScheduledPriceChangeResource\Pages;

use App\Filament\Resources\ScheduledPriceChangeResource;
use Filament\Resources\Pages\EditRecord;

class EditScheduledPriceChange extends EditRecord
{
    protected static string $resource = ScheduledPriceChangeResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['product_ids'] = empty($data['product_ids']) ? null : array_values($data['product_ids']);

        return $data;
    }
}

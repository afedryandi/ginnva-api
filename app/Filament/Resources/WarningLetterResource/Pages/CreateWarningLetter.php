<?php

namespace App\Filament\Resources\WarningLetterResource\Pages;

use App\Filament\Resources\WarningLetterResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWarningLetter extends CreateRecord
{
    protected static string $resource = WarningLetterResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! auth()->user()?->isFullAccess()) {
            $data['store_id'] = auth()->user()->store_id;
        }

        $data['issued_by'] = auth()->id();

        return $data;
    }
}

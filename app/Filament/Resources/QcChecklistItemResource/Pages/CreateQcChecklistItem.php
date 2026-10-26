<?php

namespace App\Filament\Resources\QcChecklistItemResource\Pages;

use App\Filament\Resources\QcChecklistItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQcChecklistItem extends CreateRecord
{
    protected static string $resource = QcChecklistItemResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

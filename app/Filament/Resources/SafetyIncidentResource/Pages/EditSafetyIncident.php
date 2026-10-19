<?php

namespace App\Filament\Resources\SafetyIncidentResource\Pages;

use App\Filament\Resources\SafetyIncidentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSafetyIncident extends EditRecord
{
    protected static string $resource = SafetyIncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

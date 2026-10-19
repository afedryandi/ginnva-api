<?php

namespace App\Filament\Resources\SafetyIncidentResource\Pages;

use App\Filament\Resources\SafetyIncidentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSafetyIncident extends CreateRecord
{
    protected static string $resource = SafetyIncidentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['reported_by'] = auth()->id();
        $data['status'] = 'open';

        return $data;
    }
}

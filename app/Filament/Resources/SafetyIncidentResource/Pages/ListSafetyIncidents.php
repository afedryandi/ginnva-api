<?php

namespace App\Filament\Resources\SafetyIncidentResource\Pages;

use App\Filament\Resources\SafetyIncidentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSafetyIncidents extends ListRecords
{
    protected static string $resource = SafetyIncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

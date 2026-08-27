<?php

namespace App\Filament\Resources\TechnicianResource\Pages;

use App\Filament\Resources\TechnicianResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewTechnician extends ViewRecord
{
    protected static string $resource = TechnicianResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}

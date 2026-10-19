<?php

namespace App\Filament\Resources\PpeTypeResource\Pages;

use App\Filament\Resources\PpeTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPpeType extends EditRecord
{
    protected static string $resource = PpeTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

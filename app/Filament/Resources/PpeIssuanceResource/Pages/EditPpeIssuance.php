<?php

namespace App\Filament\Resources\PpeIssuanceResource\Pages;

use App\Filament\Resources\PpeIssuanceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPpeIssuance extends EditRecord
{
    protected static string $resource = PpeIssuanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\PpeIssuanceResource\Pages;

use App\Filament\Resources\PpeIssuanceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPpeIssuances extends ListRecords
{
    protected static string $resource = PpeIssuanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

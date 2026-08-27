<?php

namespace App\Filament\Resources\ContractExtensionResource\Pages;

use App\Filament\Resources\ContractExtensionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContractExtensions extends ListRecords
{
    protected static string $resource = ContractExtensionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Perpanjang Kontrak'),
        ];
    }
}

<?php

namespace App\Filament\Resources\PartnerPointTransactionResource\Pages;

use App\Filament\Resources\PartnerPointTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPartnerPointTransactions extends ListRecords
{
    protected static string $resource = PartnerPointTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Catat Poin Manual'),
        ];
    }
}

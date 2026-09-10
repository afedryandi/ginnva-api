<?php

namespace App\Filament\Resources\SpendPromoResource\Pages;

use App\Filament\Resources\SpendPromoResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSpendPromo extends EditRecord
{
    protected static string $resource = SpendPromoResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}

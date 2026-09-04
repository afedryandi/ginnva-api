<?php

namespace App\Filament\Resources\FinanceCategoryResource\Pages;

use App\Filament\Resources\FinanceCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFinanceCategory extends EditRecord
{
    protected static string $resource = FinanceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => ! $this->record->hasTransactions()),
        ];
    }
}

<?php

namespace App\Filament\Resources\ChartOfAccountResource\Pages;

use App\Filament\Resources\ChartOfAccountResource;
use App\Models\ChartOfAccount;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChartOfAccount extends EditRecord
{
    protected static string $resource = ChartOfAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => ! $this->record->children()->exists()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['normal_balance'] = ChartOfAccount::normalBalanceFor($data['type']);

        return $data;
    }
}

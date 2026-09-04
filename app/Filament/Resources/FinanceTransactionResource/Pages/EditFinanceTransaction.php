<?php

namespace App\Filament\Resources\FinanceTransactionResource\Pages;

use App\Filament\Resources\FinanceTransactionResource;
use App\Models\FinanceCategory;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFinanceTransaction extends EditRecord
{
    protected static string $resource = FinanceTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
        ];
    }

    /**
     * Sama jaring pengaman dengan CreateFinanceTransaction — 'type'
     * disalin ulang dari kategori yang (mungkin baru) dipilih saat edit.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $category = FinanceCategory::find($data['finance_category_id']);
        if ($category) {
            $data['type'] = $category->type;
        }

        return $data;
    }
}

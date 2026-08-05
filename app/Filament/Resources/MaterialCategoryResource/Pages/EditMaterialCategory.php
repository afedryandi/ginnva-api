<?php

namespace App\Filament\Resources\MaterialCategoryResource\Pages;

use App\Filament\Resources\MaterialCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMaterialCategory extends EditRecord
{
    protected static string $resource = MaterialCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->materials()->count() === 0),
        ];
    }
}

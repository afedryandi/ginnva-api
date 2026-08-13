<?php

namespace App\Filament\Resources\ConsumableItemResource\Pages;

use App\Filament\Resources\ConsumableItemResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditConsumableItem extends EditRecord
{
    protected static string $resource = ConsumableItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
        ];
    }

    /**
     * current_stock disabled+not dehydrated di form edit — dijaga
     * eksplisit di sini juga supaya stok TIDAK PERNAH bisa berubah lewat
     * form edit, cuma lewat tombol "Catat Stok"/"Sesuaikan Stok".
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['current_stock']);

        return $data;
    }
}

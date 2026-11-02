<?php

namespace App\Filament\Resources\InventoryItemResource\Pages;

use App\Filament\Resources\InventoryItemResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditInventoryItem extends EditRecord
{
    protected static string $resource = InventoryItemResource::class;

    /** Mengganti kode gulungan harus sejalan dengan status barang (lihat InventoryItem::scrollCodeLinkProblem()). */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if ($problem = \App\Models\InventoryItem::scrollCodeLinkProblem($data['scroll_code_id'] ?? null, $record->status, $record->scroll_code_id, $record->id)) {
            Notification::make()->title('Tidak bisa disimpan')->body($problem)->danger()->send();

            $this->halt();
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
        ];
    }
}

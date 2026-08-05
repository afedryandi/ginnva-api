<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    // Sama seperti CreateRole — Role bukan model sendiri, dicatat manual.
    protected ?string $nameBeforeSave = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function ($record) {
                    activity('role')
                        ->causedBy(auth()->user())
                        ->withProperties(['attributes' => ['name' => $record->name]])
                        ->log("Role \"{$record->name}\" dihapus");
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->nameBeforeSave = $this->record->name;

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->nameBeforeSave === $this->record->name) {
            return;
        }

        activity('role')
            ->causedBy(auth()->user())
            ->performedOn($this->record)
            ->withProperties(['old' => ['name' => $this->nameBeforeSave], 'attributes' => ['name' => $this->record->name]])
            ->log("Role \"{$this->nameBeforeSave}\" diubah menjadi \"{$this->record->name}\"");
    }
}

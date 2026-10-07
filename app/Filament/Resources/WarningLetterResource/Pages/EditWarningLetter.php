<?php

namespace App\Filament\Resources\WarningLetterResource\Pages;

use App\Filament\Resources\WarningLetterResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditWarningLetter extends EditRecord
{
    protected static string $resource = WarningLetterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! auth()->user()?->isFullAccess()) {
            $data['store_id'] = auth()->user()->store_id;
        }

        if ($problem = WarningLetterResource::employeeProblem($data)) {
            Notification::make()->title('Tidak bisa disimpan')->body($problem)->danger()->send();

            $this->halt();
        }

        return $data;
    }
}

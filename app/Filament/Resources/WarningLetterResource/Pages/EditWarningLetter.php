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
            Actions\Action::make('printLetter')
                ->label('Cetak Surat')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(fn () => WarningLetterResource::downloadLetterPdf($this->getRecord())),

            Actions\DeleteAction::make()
                ->visible(fn () => ! $this->getRecord()->isLocked() && WarningLetterResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Surat yang sudah dibaca atau dibatalkan: hanya Scan Surat yang boleh dilengkapi (isi lain terkunci, juga di model).
        if ($this->getRecord()->isLocked()) {
            return \Illuminate\Support\Arr::only($data, ['document']);
        }

        if (! auth()->user()?->isFullAccess()) {
            $data['store_id'] = auth()->user()->store_id;
        }

        if ($problem = WarningLetterResource::levelProblem($data, $this->getRecord())) {
            Notification::make()->title('Tidak bisa disimpan')->body($problem)->danger()->send();

            $this->halt();
        }

        if ($problem = WarningLetterResource::employeeProblem($data)) {
            Notification::make()->title('Tidak bisa disimpan')->body($problem)->danger()->send();

            $this->halt();
        }

        return $data;
    }
}

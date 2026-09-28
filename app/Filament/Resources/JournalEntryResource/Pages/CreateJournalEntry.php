<?php

namespace App\Filament\Resources\JournalEntryResource\Pages;

use App\Filament\Resources\JournalEntryResource;
use App\Services\JournalEntryService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateJournalEntry extends CreateRecord
{
    protected static string $resource = JournalEntryResource::class;

    /**
     * Tombol tambahan "Simpan & Posting" (gap audit Jurnal Umum 2026-09-29).
     * Posting tetap tunduk pada hak posting (JournalEntryResource::canPost --
     * termasuk larangan memposting jurnal buatan sendiri untuk non-full-access);
     * kalau tidak berwenang, jurnal tetap tersimpan sebagai DRAFT.
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            \Filament\Actions\Action::make('createAndPost')
                ->label('Simpan & Posting')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Jurnal akan disimpan lalu langsung DIPOSTING dan terkunci. Koreksi selanjutnya hanya lewat jurnal pembalik.')
                ->action('createAndPost'),
            $this->getCancelFormAction(),
        ];
    }

    public function createAndPost(): void
    {
        try {
            $data = $this->mutateFormDataBeforeCreate($this->form->getState());
            $record = $this->handleRecordCreation($data);
        } catch (\Filament\Support\Exceptions\Halt) {
            return;
        }

        $this->record = $record;

        if (! JournalEntryResource::canPost($record)) {
            Notification::make()->title('Jurnal disimpan sebagai Draft')->body('Anda tidak berwenang memposting jurnal ini (mis. jurnal buatan Anda sendiri) — minta direksi memostingnya.')->warning()->send();
        } else {
            try {
                app(JournalEntryService::class)->post($record, auth()->id());
                Notification::make()->title('Jurnal disimpan & diposting')->success()->send();
            } catch (RuntimeException $e) {
                Notification::make()->title('Jurnal disimpan sebagai Draft, posting gagal')->body($e->getMessage())->danger()->send();
            }
        }

        $this->redirect(JournalEntryResource::getUrl('view', ['record' => $record]));
    }

    /**
     * Dialihkan TOTAL ke JournalEntryService::create() — TIDAK PERNAH
     * panggil static::getModel()::create($data) bawaan Filament, supaya
     * validasi balance debit=kredit (lihat komentar class-level
     * JournalEntryResource) tidak mungkin terlewat dari jalur form ini.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(JournalEntryService::class)->create(
                [
                    'entry_date' => $data['entry_date'],
                    'store_id' => $data['store_id'] ?? null,
                    'description' => $data['description'],
                    'attachment' => $data['attachment'] ?? null,
                    'created_by' => auth()->id(),
                ],
                $data['lines'] ?? []
            );
        } catch (RuntimeException $e) {
            Notification::make()
                ->title('Jurnal tidak bisa disimpan')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }
}

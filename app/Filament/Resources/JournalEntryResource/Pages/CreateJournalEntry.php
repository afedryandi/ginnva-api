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

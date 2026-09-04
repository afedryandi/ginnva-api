<?php

namespace App\Filament\Resources\JournalEntryResource\Pages;

use App\Filament\Resources\JournalEntryResource;
use App\Services\JournalEntryService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Halaman ini dipakai DUA fungsi: edit beneran untuk jurnal 'draft',
 * tampilan read-only untuk jurnal 'posted' (semua field terkunci lewat
 * ->disabled() di JournalEntryResource::form(), lihat komentarnya) —
 * supaya tidak perlu halaman View terpisah.
 */
class EditJournalEntry extends EditRecord
{
    protected static string $resource = JournalEntryResource::class;

    public function getTitle(): string
    {
        return $this->record->status === 'posted'
            ? "Jurnal {$this->record->entry_number} (Posted — terkunci)"
            : "Edit Jurnal {$this->record->entry_number}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->isDraft()),
        ];
    }

    /**
     * Tombol "Save" disembunyikan total untuk jurnal posted — field-nya
     * sudah ->disabled() (lihat form()), tapi tombol Save tetap akan
     * tampil default kalau tidak disembunyikan eksplisit di sini,
     * membingungkan karena akan selalu gagal (ditolak
     * JournalEntryService::update()) kalau tetap diklik.
     */
    protected function getFormActions(): array
    {
        return $this->record->isDraft() ? parent::getFormActions() : [];
    }

    /**
     * 'lines' BUKAN kolom/relasi Eloquent langsung di JournalEntry —
     * disuntikkan manual dari relasi lines() supaya Repeater di form
     * (yang tidak dipasang ->relationship(), lihat komentar Resource)
     * ter-fill dengan baris yang sudah ada.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lines'] = $this->record->lines->map(fn ($line) => [
            'chart_of_account_id' => $line->chart_of_account_id,
            'debit' => (float) $line->debit,
            'credit' => (float) $line->credit,
            'description' => $line->description,
        ])->all();

        return $data;
    }

    /**
     * Dialihkan TOTAL ke JournalEntryService::update() — sama alasan
     * dengan CreateJournalEntry, supaya validasi balance & guard
     * "cuma draft yang boleh diedit" selalu tertegak di satu tempat.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(JournalEntryService::class)->update(
                $record,
                [
                    'entry_date' => $data['entry_date'],
                    'store_id' => $data['store_id'] ?? null,
                    'description' => $data['description'],
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

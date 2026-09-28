<?php

namespace App\Filament\Resources\JournalEntryResource\Pages;

use App\Filament\Resources\JournalEntryResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Tampilan detail jurnal (khusus jurnal POSTED, atau siapa pun yang hanya
 * boleh melihat) -- audit Jurnal Umum 2026-09-29. Sebelumnya jurnal posted
 * memakai halaman Edit yang di-disable, tanpa total debit/kredit, selisih,
 * atau tautan ke jurnal asal/pembalik. Isi infolist ada di
 * JournalEntryResource::infolist().
 */
class ViewJournalEntry extends ViewRecord
{
    protected static string $resource = JournalEntryResource::class;

    public function getTitle(): string
    {
        return 'Jurnal ' . $this->record->entry_number . ($this->record->isPosted() ? ' (Posted — terkunci)' : ' (Draft)');
    }
}

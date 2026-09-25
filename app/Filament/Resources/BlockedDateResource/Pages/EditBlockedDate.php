<?php

namespace App\Filament\Resources\BlockedDateResource\Pages;

use App\Filament\Resources\BlockedDateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * BUG DIPERBAIKI 2026-09-25 (audit Tanggal Tidak Tersedia) -- SEBELUMNYA
 * canEdit() di BlockedDateResource sudah lengkap tapi tidak ada halaman
 * Edit sama sekali (getPages() cuma index/create) -- toggle permission
 * "update" di matrix hak akses jadi placebo. Field 'date_end' &
 * placeholder 'overlap_warning' di form (dipakai jalur Create untuk
 * blokir rentang) disembunyikan otomatis di sini lewat
 * ->visible(fn (?BlockedDate $record) => $record === null) di
 * BlockedDateResource::form() -- 1 baris BlockedDate tetap 1 tanggal
 * saat diedit, tidak ada logika "buat rentang" yang relevan di sini.
 */
class EditBlockedDate extends EditRecord
{
    protected static string $resource = BlockedDateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Sama pengaman dengan CreateBlockedDate::mutateFormDataBeforeCreate()
     * -- store_id di form ->disabled() untuk non-super-admin, TIDAK ikut
     * ter-submit kecuali eksplisit dehydrated(true).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $data['store_id'] = $this->record->store_id;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

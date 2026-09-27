<?php

namespace App\Filament\Resources\WarningLetterResource\Pages;

use App\Filament\Resources\WarningLetterResource;
use App\Services\PushNotificationService;
use Filament\Resources\Pages\CreateRecord;

class CreateWarningLetter extends CreateRecord
{
    protected static string $resource = WarningLetterResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! auth()->user()?->isFullAccess()) {
            $data['store_id'] = auth()->user()->store_id;
        }

        $data['issued_by'] = auth()->id();

        return $data;
    }

    /**
     * Gap standar enterprise diperbaiki 2026-09-27 (audit Surat
     * Peringatan) -- SEBELUMNYA tidak ada notifikasi apa pun saat SP
     * diterbitkan, karyawan hanya tahu kalau sengaja membuka tab
     * "Surat Peringatan" di app. Konsisten dengan pola push notifikasi
     * yang sudah diterapkan di modul lain sesi ini (Absensi, Izin &
     * Cuti, dll).
     */
    protected function afterCreate(): void
    {
        app(PushNotificationService::class)->sendToUsers(
            [$this->record->user_id],
            'Surat Peringatan Diterbitkan',
            'Anda menerima Surat Peringatan (' . strtoupper(str_replace('sp', 'SP ', $this->record->level)) . '). Buka app untuk lihat rincian.'
        );
    }
}

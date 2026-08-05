<?php

namespace App\Filament\Resources\BlockedDateResource\Pages;

use App\Filament\Resources\BlockedDateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlockedDate extends CreateRecord
{
    protected static string $resource = BlockedDateResource::class;

    /**
     * Field store_id di form di-disable() untuk non-super-admin (cuma
     * lihat, tidak bisa ganti toko) — tapi field disabled() di Filament
     * TIDAK ikut ter-submit kecuali eksplisit dehydrated(true). Tanpa
     * pengaman ini, store_id akan kosong saat Store Manager submit
     * (store_id NOT NULL di database), jadi create-nya selalu gagal.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $data['store_id'] = $user->store_id;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

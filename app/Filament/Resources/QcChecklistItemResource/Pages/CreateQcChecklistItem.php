<?php

namespace App\Filament\Resources\QcChecklistItemResource\Pages;

use App\Filament\Resources\QcChecklistItemResource;
use App\Models\QcChecklistItem;
use Filament\Resources\Pages\CreateRecord;

class CreateQcChecklistItem extends CreateRecord
{
    protected static string $resource = QcChecklistItemResource::class;

    // Isian Urutan tidak ada lagi di form: butir baru ditaruh di urutan paling akhir untuk layanannya; geser lewat "Urutkan Ulang" di daftar.
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sort_order'] = (int) QcChecklistItem::where('service_key', $data['service_key'])->max('sort_order') + 1;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

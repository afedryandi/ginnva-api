<?php

namespace App\Filament\Resources\QuotationResource\Pages;

use App\Filament\Resources\QuotationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuotation extends CreateRecord
{
    protected static string $resource = QuotationResource::class;

    // Tombol Buat dan Batal menempel di bawah layar saat form panjang digulir, jadi aksi utama selalu terjangkau.
    public static bool $formActionsAreSticky = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $data['store_id'] = $user->store_id;
        }

        // Ditandai 'staff' supaya QuotationObserver tidak kirim notifikasi
        // "Lead Baru" — staff yang input manual sudah tahu, tidak perlu
        // dikabari soal lead yang mereka buat sendiri.
        $data['source'] = 'staff';

        return $data;
    }
}

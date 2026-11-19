<?php

namespace App\Filament\Resources\WarrantyResource\Pages;

use App\Filament\Resources\WarrantyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWarranty extends CreateRecord
{
    protected static string $resource = WarrantyResource::class;

    // Tombol simpan dan batal menempel di bawah layar saat form digulir.
    public static bool $formActionsAreSticky = true;

    /**
     * Jaga-jaga: admin toko (store_manager) tidak boleh menyimpan
     * store_id selain miliknya sendiri, walaupun field-nya disembunyikan.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $data['store_id'] = $user->store_id;
        }

        return $data;
    }

    /** Lebar penuh supaya tata letak 2:1 (isi garansi di kiri, status review di kanan) muncul. */
    public function getMaxContentWidth(): \Filament\Support\Enums\MaxWidth|string|null
    {
        return \Filament\Support\Enums\MaxWidth::SevenExtraLarge;
    }
}

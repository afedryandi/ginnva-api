<?php

namespace App\Filament\Resources\WarrantyResource\Pages;

use App\Filament\Resources\WarrantyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWarranty extends CreateRecord
{
    protected static string $resource = WarrantyResource::class;

    /**
     * Jaga-jaga: admin toko (regional_admin) tidak boleh menyimpan
     * store_id selain miliknya sendiri, walaupun field-nya disembunyikan.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user && ! $user->hasRole('super_admin')) {
            $data['store_id'] = $user->store_id;
        }

        return $data;
    }
}

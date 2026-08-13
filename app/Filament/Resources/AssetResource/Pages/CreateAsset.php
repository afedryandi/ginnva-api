<?php

namespace App\Filament\Resources\AssetResource\Pages;

use App\Filament\Resources\AssetResource;
use App\Models\Asset;
use Filament\Resources\Pages\CreateRecord;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['asset_tag'] = Asset::generateAssetTag();
        $data['created_by'] = auth()->id();

        // Field "Lokasi (Toko)" dikunci+tidak ikut submit untuk
        // non-full-access (lihat AssetResource::form()) — dipaksa ke
        // toko staff itu sendiri di sini, supaya aset yang baru dibuat
        // tidak "hilang" dari pandangannya sendiri gara-gara store_id
        // kosong (AssetResource::getEloquentQuery() scope ketat).
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $data['store_id'] = auth()->user()?->store_id;
        }

        return $data;
    }
}

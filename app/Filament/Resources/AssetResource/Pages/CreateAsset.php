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
        // kosong (AssetResource::getEloquentQuery() scope ketat). Lihat
        // Asset::defaultStoreIdFor() — satu sumber kebenaran yang sama
        // dipakai di sini dan di getEloquentQuery()/AssetController.
        // Full-access TETAP dibiarkan pakai pilihan form sendiri
        // ($data['store_id'] sudah terisi dari situ).
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $data['store_id'] = Asset::defaultStoreIdFor(auth()->user());
        }

        return $data;
    }
}
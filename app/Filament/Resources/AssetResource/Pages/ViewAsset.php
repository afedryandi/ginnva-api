<?php

namespace App\Filament\Resources\AssetResource\Pages;

use App\Filament\Resources\AssetResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Halaman Lihat: klik baris di daftar membuka ini (hanya baca), bukan form Edit, supaya data aset tidak terubah tanpa sengaja.
 * Tombol Ubah hanya muncul bagi yang boleh mengubah (AssetResource::canEdit()).
 */
class ViewAsset extends ViewRecord
{
    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}

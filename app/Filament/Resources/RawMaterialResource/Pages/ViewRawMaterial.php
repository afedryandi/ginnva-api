<?php

namespace App\Filament\Resources\RawMaterialResource\Pages;

use App\Filament\Resources\RawMaterialResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Halaman Lihat: klik baris di daftar membuka ini (hanya baca), bukan form Edit, supaya data master tidak terubah tanpa sengaja.
 * Tombol Ubah hanya muncul bagi yang boleh mengubah (RawMaterialResource::canEdit()).
 */
class ViewRawMaterial extends ViewRecord
{
    protected static string $resource = RawMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}

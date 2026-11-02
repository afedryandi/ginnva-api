<?php

namespace App\Filament\Resources\ConsumableItemResource\Pages;

use App\Filament\Resources\ConsumableItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Halaman Lihat: klik baris di daftar membuka ini (hanya baca), bukan form Edit, supaya data master tidak terubah tanpa sengaja.
 * Tombol Ubah hanya muncul bagi yang boleh mengubah (ConsumableItemResource::canEdit()).
 */
class ViewConsumableItem extends ViewRecord
{
    protected static string $resource = ConsumableItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}

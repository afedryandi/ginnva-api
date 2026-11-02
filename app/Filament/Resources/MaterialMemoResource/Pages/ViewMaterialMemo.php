<?php

namespace App\Filament\Resources\MaterialMemoResource\Pages;

use App\Filament\Resources\MaterialMemoResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Halaman Lihat: memo dan barangnya hanya dibaca. Mengubah memo dan mencatat barang butuh izin Ubah (MaterialMemoResource::canEdit()).
 */
class ViewMaterialMemo extends ViewRecord
{
    protected static string $resource = MaterialMemoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\RawMaterialResource\Pages;

use App\Filament\Resources\RawMaterialResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRawMaterial extends EditRecord
{
    protected static string $resource = RawMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
        ];
    }

    /**
     * current_stock disabled+not dehydrated di form edit (lihat
     * RawMaterialResource::form()) — kalau field-nya sengaja dilepas dari
     * payload, Livewire tetap kirim state lama lewat hidden state kadang;
     * dijaga eksplisit di sini supaya stok TIDAK PERNAH bisa berubah lewat
     * form edit, cuma lewat tombol "Catat Stok".
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['current_stock']);

        return $data;
    }
}

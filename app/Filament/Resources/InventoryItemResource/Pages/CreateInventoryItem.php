<?php

namespace App\Filament\Resources\InventoryItemResource\Pages;

use App\Filament\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use Filament\Resources\Pages\CreateRecord;

class CreateInventoryItem extends CreateRecord
{
    protected static string $resource = InventoryItemResource::class;

    /**
     * code bukan field yang diisi manual di form — dibuat otomatis dan
     * dikodekan ke QR. Barang baru selalu berstatus in_stock (baru
     * didaftarkan, belum pernah keluar).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['code'] = InventoryItem::generateCode();
        $data['status'] = 'in_stock';
        $data['created_by'] = auth()->id();

        return $data;
    }

    /**
     * Catat baris riwayat "masuk" pertama -- SEBELUMNYA barang yang didaftarkan lewat form ini tidak punya riwayat sama
     * sekali (tidak ada jejak kapan/oleh siapa pertama tercatat), padahal barang hasil Import Excel sudah dicatat.
     */
    protected function afterCreate(): void
    {
        $this->record->movements()->create([
            'type' => 'in',
            'note' => 'Didaftarkan lewat panel admin',
            'user_id' => auth()->id(),
        ]);
    }
}

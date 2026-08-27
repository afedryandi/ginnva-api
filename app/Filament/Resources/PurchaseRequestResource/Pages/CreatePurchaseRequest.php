<?php

namespace App\Filament\Resources\PurchaseRequestResource\Pages;

use App\Filament\Resources\PurchaseRequestResource;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseRequest extends CreateRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    /**
     * Nama & satuan barang katalog (Bahan Baku/Barang Habis Pakai) diambil
     * dari baris aslinya di sini, BUKAN dari form — item_id cuma dipakai
     * untuk isi Select-nya, nama/satuan yang benar-benar disimpan selalu
     * snapshot dari sumbernya supaya tidak pernah beda dari nama asli di
     * katalog stok. Aset baru tetap pakai item_name yang diketik manual.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! auth()->user()?->isFullAccess()) {
            $data['store_id'] = auth()->user()->store_id;
        }

        $data['requested_by'] = auth()->id();
        $data['status'] = 'pending';

        if (in_array($data['item_type'], ['raw_material', 'consumable_item'])) {
            $item = $data['item_type'] === 'raw_material'
                ? RawMaterial::find($data['item_id'])
                : ConsumableItem::find($data['item_id']);

            $data['item_name'] = $item?->name ?? $data['item_name'] ?? '(barang tidak ditemukan)';
            $data['unit'] = $item?->unit;
        } else {
            $data['item_id'] = null;
            $data['unit'] = null;
        }

        return $data;
    }
}

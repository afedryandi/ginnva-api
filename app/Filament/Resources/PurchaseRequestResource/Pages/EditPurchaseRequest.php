<?php

namespace App\Filament\Resources\PurchaseRequestResource\Pages;

use App\Filament\Resources\PurchaseRequestResource;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseRequest extends EditRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess() && $this->record->status === 'pending'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! auth()->user()?->isFullAccess()) {
            $data['store_id'] = auth()->user()->store_id;
        }

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

        // Kolom hasil review dikunci total di form Edit biasa — cuma bisa
        // berubah lewat tombol Setujui/Tolak/Tandai Terpenuhi di listing,
        // supaya tidak ada jalan pintas ubah status tanpa jejak reviewer.
        $data['status'] = $this->record->status;
        $data['reviewed_by'] = $this->record->reviewed_by;
        $data['reviewed_at'] = $this->record->reviewed_at;
        $data['review_note'] = $this->record->review_note;
        $data['fulfilled_at'] = $this->record->fulfilled_at;

        return $data;
    }
}

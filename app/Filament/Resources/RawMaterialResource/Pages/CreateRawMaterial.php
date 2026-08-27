<?php

namespace App\Filament\Resources\RawMaterialResource\Pages;

use App\Filament\Resources\RawMaterialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRawMaterial extends CreateRecord
{
    protected static string $resource = RawMaterialResource::class;

    private float $initialStock = 0;

    /**
     * Stok awal TIDAK langsung disimpan ke kolom current_stock di sini —
     * ditampung dulu, lalu dicatat lewat RawMaterial::recordMovement() di
     * afterCreate() supaya otomatis tercatat sebagai 1 batch (sama seperti
     * "Catat Stok"), bukan cuma angka current_stock polos tanpa jejak.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        $this->initialStock = (float) ($data['current_stock'] ?? 0);
        $data['current_stock'] = 0;

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->initialStock <= 0) {
            return;
        }

        $this->record->recordMovement(
            'in',
            $this->initialStock,
            auth()->id(),
            'Stok awal saat pendaftaran.',
            $this->record->received_date?->toDateString(),
            $this->record->expiry_date?->toDateString(),
            $this->record->unit_cost !== null ? (float) $this->record->unit_cost : null,
        );
    }
}
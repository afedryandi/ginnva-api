<?php

namespace App\Filament\Resources\ConsumableItemResource\Pages;

use App\Filament\Resources\ConsumableItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateConsumableItem extends CreateRecord
{
    protected static string $resource = ConsumableItemResource::class;

    private float $initialStock = 0;

    /**
     * Sama pola dengan CreateRawMaterial — stok awal TIDAK langsung
     * ditulis ke current_stock, ditampung dulu lalu dicatat lewat
     * ConsumableItem::recordMovement() di afterCreate() supaya ada
     * jejak riwayatnya (movements), bukan angka polos tanpa riwayat.
     * Sebelumnya cuma jalur Import Excel yang benar begini, form Create
     * biasa langsung nulis current_stock — tidak konsisten.
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
        );
    }
}
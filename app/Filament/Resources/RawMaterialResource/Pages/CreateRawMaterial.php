<?php

namespace App\Filament\Resources\RawMaterialResource\Pages;

use App\Filament\Resources\RawMaterialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRawMaterial extends CreateRecord
{
    protected static string $resource = RawMaterialResource::class;

    private float $initialStock = 0;

    private int $initialContainerCount = 1;

    /**
     * Stok awal & jumlah botol/wadah TIDAK langsung disimpan ke kolom
     * current_stock di sini — ditampung dulu, lalu dicatat lewat
     * RawMaterial::recordMovement() di afterCreate() supaya otomatis
     * kebagi jadi batch per botol/wadah (sama seperti "Catat Stok"),
     * bukan cuma angka current_stock polos tanpa batch pendukung.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        $this->initialStock = (float) ($data['current_stock'] ?? 0);
        $this->initialContainerCount = max(1, (int) ($data['container_count'] ?? 1));

        $data['current_stock'] = 0;
        unset($data['container_count']);

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
            $this->initialContainerCount,
        );
    }
}

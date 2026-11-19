<?php

namespace App\Filament\Resources\RawMaterialResource\Pages;

use App\Filament\Resources\RawMaterialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRawMaterial extends CreateRecord
{
    protected static string $resource = RawMaterialResource::class;

    // Tombol simpan dan batal menempel di bawah layar saat form digulir.
    public static bool $formActionsAreSticky = true;

    /** Lebar penuh supaya tata letak 2:1 (data di kiri, pengaturan di kanan) muncul. */
    public function getMaxContentWidth(): \Filament\Support\Enums\MaxWidth|string|null
    {
        return \Filament\Support\Enums\MaxWidth::SevenExtraLarge;
    }

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

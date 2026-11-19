<?php

namespace App\Filament\Resources\RawMaterialResource\Pages;

use App\Filament\Resources\RawMaterialResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRawMaterial extends EditRecord
{
    protected static string $resource = RawMaterialResource::class;

    // Tombol simpan dan batal menempel di bawah layar saat form digulir.
    public static bool $formActionsAreSticky = true;

    /** Lebar penuh supaya tata letak 2:1 (data di kiri, pengaturan di kanan) muncul. */
    public function getMaxContentWidth(): \Filament\Support\Enums\MaxWidth|string|null
    {
        return \Filament\Support\Enums\MaxWidth::SevenExtraLarge;
    }

    /** Kode dan kategori di bawah judul, supaya bahan langsung dikenali. */
    public function getSubheading(): ?string
    {
        return collect([$this->record->code, $this->record->category])->filter()->implode(' · ') ?: null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                // Riwayat stok ikut terhapus oleh cascade di database; yang sudah punya riwayat tidak boleh dihapus.
                ->before(function (Actions\DeleteAction $action) {
                    $blocker = \App\Support\StockMasterDeletion::blocker($this->record, 'Bahan baku');

                    if ($blocker !== null) {
                        \Filament\Notifications\Notification::make()->title('Tidak bisa menghapus bahan baku ini')->body($blocker)->danger()->send();
                        $action->halt();
                    }
                }),
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

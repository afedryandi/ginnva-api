<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Services\InvoiceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use RuntimeException;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    /**
     * Repeater "items" SENGAJA bukan ->relationship() bawaan Filament
     * (lihat InvoiceResource::form()) -- jadi WAJIB diisi manual di
     * sini dari relasi asli record, atau form muncul kosong saat edit.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['items'] = $this->record->items()->get()->map(fn ($item) => [
            'film_product_id' => $item->film_product_id,
            'name' => $item->name,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'price' => $item->price,
            'discount_percent' => $item->discount_percent,
            'note' => $item->note,
        ])->toArray();

        return $data;
    }

    protected function handleRecordUpdate($record, array $data): \Illuminate\Database\Eloquent\Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        try {
            return app(InvoiceService::class)->update($record, $data, $items);
        } catch (RuntimeException $e) {
            Notification::make()
                ->title('Invoice tidak bisa disimpan')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }
}

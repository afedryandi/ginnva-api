<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Services\InvoiceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    /**
     * Semua tulis-menulis (nomor invoice, item, rekalkulasi total)
     * WAJIB lewat InvoiceService -- lihat catatan di
     * InvoiceResource::form() soal Repeater "items" yang SENGAJA bukan
     * ->relationship() bawaan Filament.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        $data['created_by'] = auth()->id();

        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $data['store_id'] = auth()->user()?->store_id;
        }

        try {
            return app(InvoiceService::class)->create($data, $items, auth()->id());
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

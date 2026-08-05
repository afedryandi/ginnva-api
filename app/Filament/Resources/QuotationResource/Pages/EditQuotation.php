<?php

namespace App\Filament\Resources\QuotationResource\Pages;

use App\Filament\Resources\QuotationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditQuotation extends EditRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Isi vehicle_brand otomatis dari relasi saat edit,
        // supaya dropdown tipe kendaraan bisa terbuka dengan benar.
        if (! empty($data['vehicle_id'])) {
            $vehicle = \App\Models\Vehicle::find($data['vehicle_id']);
            $data['vehicle_brand'] = $vehicle?->brand;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $data['store_id'] = $user->store_id;
        }

        // vehicle_brand adalah field virtual, tidak perlu disimpan
        unset($data['vehicle_brand']);

        return $data;
    }
}

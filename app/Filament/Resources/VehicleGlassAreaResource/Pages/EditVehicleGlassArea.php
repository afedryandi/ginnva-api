<?php

namespace App\Filament\Resources\VehicleGlassAreaResource\Pages;

use App\Filament\Resources\VehicleGlassAreaResource;
use App\Models\VehicleGlassArea;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditVehicleGlassArea extends EditRecord
{
    protected static string $resource = VehicleGlassAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $exists = VehicleGlassArea::where('brand_key', VehicleGlassArea::keyOf($data['brand']))
            ->where('model_key', VehicleGlassArea::keyOf($data['model']))
            ->where('id', '!=', $this->record->id)
            ->exists();

        if ($exists) {
            Notification::make()->title('Luas kaca sudah terdaftar')->body('Merek dan tipe ini sudah dipakai baris lain.')->danger()->send();

            throw new Halt();
        }

        // Diubah tangan: bukan lagi murni hasil impor.
        $data['source'] = 'manual';

        return $data;
    }
}

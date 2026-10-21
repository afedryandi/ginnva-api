<?php

namespace App\Filament\Resources\VehicleGlassAreaResource\Pages;

use App\Filament\Resources\VehicleGlassAreaResource;
use App\Models\VehicleGlassArea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateVehicleGlassArea extends CreateRecord
{
    protected static string $resource = VehicleGlassAreaResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Merek+model sama (tanpa membedakan huruf besar/kecil dan spasi) hanya boleh sekali.
        $exists = VehicleGlassArea::where('brand_key', VehicleGlassArea::keyOf($data['brand']))
            ->where('model_key', VehicleGlassArea::keyOf($data['model']))
            ->exists();

        if ($exists) {
            Notification::make()->title('Luas kaca sudah terdaftar')->body('Merek dan tipe ini sudah ada. Ubah barisnya, jangan buat baru.')->danger()->send();

            throw new Halt();
        }

        $data['source'] = 'manual';

        return $data;
    }
}

<?php

namespace App\Filament\Resources\VehicleResource\Pages;

use App\Filament\Resources\VehicleResource;
use App\Models\Vehicle;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditVehicle extends EditRecord
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Sama pola dengan CreateVehicle::mutateFormDataBeforeCreate() —
     * lihat komentar di sana untuk penjelasan lengkap kenapa ->unique()
     * di form saja tidak cukup begitu variant dikosongkan. Record yang
     * sedang diedit sendiri dikecualikan dari pengecekan (bukan
     * "bentrok dengan dirinya sendiri"). Ditemukan & diperbaiki
     * 2026-09-01.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $exists = Vehicle::where('brand', $data['brand'])
            ->where('model', $data['model'] ?? null)
            ->where('variant', $data['variant'] ?? null)
            ->where('id', '!=', $this->record->id)
            ->exists();

        if ($exists) {
            Notification::make()
                ->title('Kendaraan sudah terdaftar')
                ->body('Kendaraan dengan merek, model, dan varian yang sama sudah ada.')
                ->danger()
                ->send();

            throw new Halt();
        }

        return $data;
    }
}

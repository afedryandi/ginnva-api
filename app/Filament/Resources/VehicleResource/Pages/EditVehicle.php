<?php

namespace App\Filament\Resources\VehicleResource\Pages;

use App\Filament\Resources\VehicleResource;
use App\Models\Vehicle;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\QueryException;
use Filament\Support\Exceptions\Halt;

class EditVehicle extends EditRecord
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Sama dengan tombol Hapus di daftar: kendaraan yang masih dipakai quotation / studi kasus ditolak database
            // (restrict); tanpa penangkapan ini staf melihat error 500 mentah dari halaman ubah.
            Actions\DeleteAction::make()
                ->action(function () {
                    try {
                        $this->record->delete();
                    } catch (QueryException $e) {
                        Notification::make()
                            ->title('Tidak bisa menghapus kendaraan ini')
                            ->body('Data kendaraan ini masih dipakai oleh quotation atau studi kasus yang terdaftar.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()->title('Kendaraan dihapus')->success()->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
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
        // Spasi di tepi dibuang dan isian spasi-saja dianggap kosong: "Honda " dan "Honda" adalah merek yang sama, dan model/varian
        // kosong harus null (bukan string kosong) supaya pengecekan duplikat di bawah mengenalinya.
        $data['brand'] = trim((string) ($data['brand'] ?? ''));
        $data['model'] = filled(trim((string) ($data['model'] ?? ''))) ? trim($data['model']) : null;
        $data['variant'] = filled(trim((string) ($data['variant'] ?? ''))) ? trim($data['variant']) : null;

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

<?php

namespace App\Filament\Resources\VehicleResource\Pages;

use App\Filament\Resources\VehicleResource;
use App\Models\Vehicle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateVehicle extends CreateRecord
{
    protected static string $resource = VehicleResource::class;

    /**
     * SEBELUMNYA cuma andalkan ->unique() di field 'variant'
     * (VehicleResource::form()) untuk cegah duplikat brand+model+variant
     * — TERNYATA rule itu diam-diam TIDAK PERNAH JALAN begitu variant
     * dikosongkan. Laravel Validator melewati SEMUA rule non-required
     * (termasuk unique) untuk field yang ->nullable() dan nilainya
     * kosong -- query whereNull yang dimaksud di komentar lama tidak
     * pernah sempat dibangun sama sekali, karena rule-nya sendiri
     * di-skip duluan sebelum sampai ke situ. Dikonfirmasi lewat testing
     * manual live: 2 kendaraan Merek+Tipe sama dengan Varian sama-sama
     * kosong berhasil tersimpan dobel tanpa ditolak.
     *
     * Dicek ulang di sini secara manual (PHP biasa, tidak kena
     * skip-validator itu) sebagai jaring pengaman terakhir — field
     * ->unique() di form TETAP dipertahankan untuk kasus varian TERISI
     * (kasih feedback instan sambil ngetik), ini cuma menutup celah
     * spesifik varian kosong. Ditemukan & diperbaiki 2026-09-01.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $exists = Vehicle::where('brand', $data['brand'])
            ->where('model', $data['model'] ?? null)
            ->where('variant', $data['variant'] ?? null)
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

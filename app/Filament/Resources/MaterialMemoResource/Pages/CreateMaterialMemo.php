<?php

namespace App\Filament\Resources\MaterialMemoResource\Pages;

use App\Filament\Resources\MaterialMemoResource;
use App\Models\MaterialMemo;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateMaterialMemo extends CreateRecord
{
    protected static string $resource = MaterialMemoResource::class;

    /** Isi awal dari ?booking={id} (tombol "Bahan Terpakai" di klaim garansi): booking, toko, dan info kendaraan. Belum tersimpan. */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill();

        $this->prefillFromBooking();

        $this->callHook('afterFill');
    }

    private function prefillFromBooking(): void
    {
        $id = request()->query('booking');
        $user = auth()->user();

        if (! $id || ! $user) {
            return;
        }

        $booking = \App\Models\Booking::query()->find($id);

        // Satu booking satu memo; staf non-full-access hanya untuk booking di tokonya sendiri.
        if (! $booking || $booking->materialMemo()->exists()) {
            return;
        }

        if (! $user->isFullAccess() && (int) $booking->store_id !== (int) $user->store_id) {
            return;
        }

        $vehicle = trim(implode(' ', array_filter([$booking->vehicle_brand, $booking->vehicle_model])));
        $info = trim($vehicle . ($booking->vehicle_plate ? ($vehicle !== '' ? ' - ' : '') . $booking->vehicle_plate : ''));

        $this->form->fill([
            ...$this->form->getRawState(),
            'booking_id' => $booking->id,
            'store_id' => $booking->store_id,
            'vehicle_info' => $info !== '' ? $info : null,
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        // Kolom toko hanya di-disable di form (state-nya masih bisa dimanipulasi dari klien) -- staf non-full-access
        // dipaksa ke tokonya sendiri di sisi server.
        if (! auth()->user()?->isFullAccess()) {
            $data['store_id'] = auth()->user()?->store_id;
        }

        return $data;
    }

    /**
     * Bug diperbaiki 2026-09-30 (audit Memo Pengambilan/Pengembalian) --
     * SEBELUMNYA generateMemoNumber() dipanggil telanjang di
     * mutateFormDataBeforeCreate() tanpa retry, beda dari jalur mobile
     * (Api\Staff\MaterialMemoController::store()) yang sudah dibungkus
     * retry loop untuk race condition ini (2 request nyaris bersamaan
     * hitung-lalu-format nomor yang sama, ditolak UNIQUE constraint
     * memo_number sebagai QueryException mentah/500). Disamakan polanya
     * di sini -- override handleRecordCreation() (bukan
     * mutateFormDataBeforeCreate()) supaya create() ulang bisa dicoba
     * dengan nomor baru tiap percobaan.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                return DB::transaction(function () use ($data) {
                    return MaterialMemo::create($data + [
                        'memo_number' => MaterialMemo::generateMemoNumber(),
                    ]);
                });
            } catch (QueryException $e) {
                if ($attempts >= 3 || ! str_contains($e->getMessage(), 'memo_number')) {
                    throw $e;
                }
            }
        }
    }
}

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
        $this->prefillFromSpk();

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

        $this->form->fill([
            ...$this->form->getRawState(),
            'booking_id' => $booking->id,
            'store_id' => $booking->store_id,
            'vehicle_brand' => $booking->vehicle_brand,
            'vehicle_model' => $booking->vehicle_model,
            'vehicle_variant' => $booking->vehicle_variant,
            'vehicle_year' => $booking->vehicle_year,
            'vehicle_plate' => $booking->vehicle_plate,
        ]);
    }

    /** Isi awal dari ?spk={id} (tombol "Buat Memo Barang" di SPK): SPK, nomornya, toko, dan kendaraan. Belum tersimpan. */
    private function prefillFromSpk(): void
    {
        $id = request()->query('spk');
        $user = auth()->user();

        if (! $id || ! $user) {
            return;
        }

        $spk = \App\Models\Spk::query()->find($id);

        if (! $spk) {
            return;
        }

        if (! $user->isFullAccess() && (int) $spk->store_id !== (int) $user->store_id) {
            return;
        }

        $this->form->fill([
            ...$this->form->getRawState(),
            'spk_id' => $spk->id,
            'spk_number' => $spk->spk_number,
            'store_id' => $spk->store_id,
            'vehicle_brand' => $spk->vehicle_brand,
            'vehicle_model' => $spk->vehicle_model,
            'vehicle_variant' => $spk->vehicle_variant,
            'vehicle_year' => $spk->vehicle_year,
            'vehicle_plate' => $spk->vehicle_plate,
        ]);
    }

    protected function afterCreate(): void
    {
        if ($this->record->isRequestFlow()) {
            app(\App\Services\MaterialMemoRequestService::class)->notifyNewRequest($this->record);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        // SPK yang dipilih melengkapi nomor SPK dan menautkan booking-nya (bila belum punya memo lain).
        $data = MaterialMemo::completeFromSpk($data);

        // Rombakan alur (Fase 0): bila diwajibkan, memo baru harus terikat booking (langsung atau lewat SPK-nya).
        if (config('features.booking_required_for_spk_memo') && blank($data['booking_id'] ?? null)) {
            \Filament\Notifications\Notification::make()
                ->title('Memo harus dibuat dari SPK atau booking')
                ->body('Pilih SPK yang terikat booking, atau buat booking dulu.')
                ->danger()
                ->persistent()
                ->send();

            throw new \Filament\Support\Exceptions\Halt();
        }

        // Non-akses-penuh: memo diajukan (setujui store manager, serahkan gudang); akses penuh tetap langsung.
        if (! auth()->user()?->isFullAccess()) {
            $data['flow'] = \App\Models\MaterialMemo::FLOW_REQUEST;
            $data['request_status'] = \App\Models\MaterialMemo::REQ_PENDING;
            $data['submitted_at'] = now();
        }

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

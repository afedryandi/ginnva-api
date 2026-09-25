<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Carbon;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Validasi kapasitas DIJALANKAN DI SINI (bukan di beforeCreate())
     * supaya kerja langsung dari parameter $data yang dijamin akurat
     * (nilai yang BARU DISUBMIT staff) — SEBELUMNYA pakai $this->data di
     * beforeCreate(), yang ternyata TIDAK bisa diandalkan mencerminkan
     * submit terbaru (bug: validasi selalu ke-skip diam-diam, booking
     * 'confirmed' yang bentrok kapasitas tetap lolos tersimpan tanpa
     * pernah ditolak).
     *
     * Redesain 2026-09-25 — TIDAK ADA LAGI 'capacities' yang ditangkap/
     * dibuang di sini: kapasitas sekarang dibaca otomatis dari
     * Booking::capacityForDate() (Store::install_capacity_per_day atau
     * StoreCapacityOverride), bukan lagi diisi manual staff tiap submit.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Field store_id di form ->disabled() untuk non-super-admin (cuma
        // lihat, tidak bisa ganti toko) — field disabled() di Filament
        // TIDAK ikut ter-submit kecuali eksplisit dehydrated(true). Tanpa
        // pengaman ini, $data['store_id'] tidak ada sama sekali saat Store
        // Manager submit, bikin "Undefined array key store_id" crash 500
        // begitu status confirmed (baris di bawah butuh store_id). Pola
        // sama dengan CreateBlockedDate::mutateFormDataBeforeCreate().
        // Ditemukan lewat testing manual live 2026-08-31.
        $user = auth()->user();
        if ($user && ! $user->isFullAccess()) {
            $data['store_id'] = $user->store_id;
        }

        if (($data['status'] ?? null) === 'confirmed') {
            $durationDays = max(1, (int) ($data['duration_days'] ?? 1));

            $fullDates = Booking::fullDatesInRange(
                (int) $data['store_id'],
                Carbon::parse($data['preferred_date']),
                $durationDays,
            );

            if (! empty($fullDates)) {
                Notification::make()
                    ->title('Kapasitas instalasi penuh')
                    ->body('Tanggal berikut sudah mencapai kapasitas maksimal toko: ' . implode(', ', $fullDates) . '. Pilih tanggal lain, sesuaikan kapasitas lewat Kalender Kapasitas, atau simpan dulu sebagai "Menunggu Konfirmasi" sampai ada slot yang kosong.')
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt();
            }
        }

        return $data;
    }

    /**
     * Booking baru, jadi SEMUA watcher yang dipilih staff di form pasti
     * "baru" (tidak ada watcher lama yang perlu di-diff) — kirim email
     * pemberitahuan ke semuanya, sama seperti assign lewat app staff.
     * afterCreate() dijamin jalan SETELAH Filament sinkron relasi
     * many-to-many (installers/watchers), jadi $this->record->watchers()
     * di sini sudah mencerminkan pilihan staff yang baru disimpan.
     */
    protected function afterCreate(): void
    {
        $watcherIds = $this->record->watchers()->pluck('users.id')->all();

        BookingResource::notifyNewWatchers($this->record, $watcherIds, auth()->user()?->name ?? 'Admin');
    }
}

<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        // Booking aktif (pending/confirmed): tanggal tidak boleh lampau & toko
        // harus buka di hari itu -- sama dengan mobile & reschedule.
        if (in_array($data['status'] ?? 'pending', ['pending', 'confirmed'], true) && ! empty($data['preferred_date'])) {
            $day = Carbon::parse($data['preferred_date'])->startOfDay();
            $store = ! empty($data['store_id']) ? \App\Models\Store::find($data['store_id']) : null;

            if ($day->lt(today())) {
                Notification::make()
                    ->title('Tanggal sudah lewat')
                    ->body('Booking yang masih berjalan tidak bisa bertanggal lampau. Ubah tanggalnya, atau set status ke "Selesai" kalau mencatat pekerjaan yang sudah terjadi.')
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt();
            }

            if ($store?->isClosedOn($day)) {
                Notification::make()
                    ->title('Toko tutup di tanggal itu')
                    ->body('Toko libur mingguan atau tanggal tersebut diblokir. Pilih tanggal lain.')
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt();
            }
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
     * Cek kapasitas ULANG di dalam transaction + lock toko (diperbaiki
     * 2026-10-02), lihat catatan di EditBooking::handleRecordUpdate().
     */
    protected function handleRecordCreation(array $data): Model
    {
        // Bukan kolom booking (field khusus form Edit).
        unset($data['form_version'], $data['reschedule_reason']);

        return DB::transaction(function () use ($data) {
            // Tanggal lampau tidak boleh langsung dikonfirmasi (sama dengan
            // jalur mobile, diperbaiki 2026-10-02).
            if (($data['status'] ?? null) === 'confirmed' && Carbon::parse($data['preferred_date'])->startOfDay()->lt(today())) {
                Notification::make()
                    ->title('Tanggal sudah lewat')
                    ->body('Booking dengan tanggal yang sudah lewat tidak bisa langsung dikonfirmasi. Simpan sebagai "Menunggu Konfirmasi" atau ubah tanggalnya.')
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt();
            }

            if (($data['status'] ?? null) === 'confirmed') {
                $fullDates = Booking::fullDatesInRangeLocked(
                    (int) $data['store_id'],
                    Carbon::parse($data['preferred_date']),
                    max(1, (int) ($data['duration_days'] ?? 1)),
                );

                if (! empty($fullDates)) {
                    Notification::make()
                        ->title('Kapasitas instalasi penuh')
                        ->body('Tanggal berikut sudah mencapai kapasitas maksimal toko: ' . implode(', ', $fullDates) . '.')
                        ->danger()
                        ->persistent()
                        ->send();

                    throw new Halt();
                }
            }

            return parent::handleRecordCreation($data);
        });
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

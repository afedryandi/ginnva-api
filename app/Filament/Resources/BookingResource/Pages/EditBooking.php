<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Carbon;

class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    /**
     * SEBELUMNYA Repeater 'capacities' kosong sama sekali begitu halaman
     * Edit dibuka — ->default() Filament TERNYATA cuma jalan untuk form
     * Create, tidak pernah dipanggil untuk record yang sudah ada. Baris
     * baru muncul kalau staff (tidak sengaja) sentuh field Tanggal/
     * Durasi (memicu afterStateUpdated). Kalau staff langsung ubah
     * Status ke Confirmed & Simpan tanpa sentuh itu, validasi cross-check
     * di mutateFormDataBeforeSave() (BENAR) menolak submit karena
     * kapasitas kosong — tapi staff bingung karena kolomnya memang tidak
     * pernah terisi. Isi manual di sini begitu form dibuka. Ditemukan &
     * diperbaiki 2026-08-28.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // BUG SUSULAN: ambil preferred_date dari $data['preferred_date']
        // (array mentah hasil serialisasi) bikin tanggalnya geser mundur
        // 1 hari — beda dari Placeholder "Rentang Tanggal Pengerjaan" yang
        // ambil dari state form live ($get()), bukan dari array ini. Ambil
        // langsung dari $this->record (Carbon yang sudah pasti benar,
        // ->toDateString() menghindari ambiguitas timezone) supaya sama
        // persis dengan yang dipakai Placeholder. Ditemukan & diperbaiki
        // 2026-08-28.
        $data['capacities'] = BookingResource::computeCapacityRows(
            $this->record->store_id,
            $this->record->preferred_date?->toDateString(),
            $this->record->duration_days,
        );

        return $data;
    }

    // Watcher (direksi) SEBELUM disimpan — ditangkap di
    // mutateFormDataBeforeSave() (dipanggil SEBELUM Filament sinkron
    // relasi many-to-many) supaya afterSave() bisa diff "siapa yang
    // BARU ditambahkan" vs yang sudah lama, cuma watcher baru yang perlu
    // dikirimi email pemberitahuan.
    private array $existingWatcherIds = [];

    /**
     * Validasi kapasitas DIJALANKAN DI SINI (bukan di beforeSave()) supaya
     * kerja langsung dari parameter $data yang dijamin akurat (nilai yang
     * BARU DISUBMIT staff) — SEBELUMNYA pakai $this->data di beforeSave(),
     * yang ternyata TIDAK bisa diandalkan mencerminkan submit terbaru
     * (bug: validasi selalu ke-skip diam-diam, booking 'confirmed' yang
     * bentrok kapasitas tetap lolos tersimpan tanpa pernah ditolak).
     * capacities (kapasitas per tanggal) juga ditangkap & dibuang di sini
     * sekalian karena bukan kolom di tabel bookings. Record yang sedang
     * diedit dikecualikan dari hitungan kapasitas (bukan "nambah 1
     * booking baru", cuma mengevaluasi ulang dirinya sendiri).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->existingWatcherIds = $this->record->watchers()->pluck('users.id')->all();

        $capacityByDate = collect($data['capacities'] ?? [])
            ->filter(fn ($row) => ! empty($row['date']))
            ->mapWithKeys(fn ($row) => [Carbon::parse($row['date'])->toDateString() => max(1, (int) ($row['capacity'] ?? 1))])
            ->all();
        unset($data['capacities']);

        if (($data['status'] ?? null) === 'confirmed') {
            $durationDays = max(1, (int) ($data['duration_days'] ?? 1));

            // SEBELUMNYA kalau Repeater 'capacities' kosong/tidak lengkap
            // untuk sebagian tanggal (mis. race Livewire saat form pertama
            // dibuka, sebelum store_id/preferred_date ter-hidrasi), sistem
            // DIAM-DIAM fallback ke kapasitas default 3 di
            // fullDatesInRange() alih-alih menolak — jadi approve bisa
            // lolos padahal kapasitas sebenarnya sudah penuh (dikonfirmasi
            // bug nyata: 3 booking confirmed lolos di toko berkapasitas 1).
            // Cross-check yang sama dengan endpoint mobile
            // Staff\BookingController::confirm() — SEBELUMNYA cuma ada di
            // situ, lupa ditambahkan di sini juga. Lihat audit modul
            // Booking, perbaikan susulan 2026-08-28.
            $expectedDates = Booking::workingDatesInRange((int) $data['store_id'], Carbon::parse($data['preferred_date']), $durationDays);
            $missingDates = array_diff($expectedDates, array_keys($capacityByDate));

            if (! empty($missingDates)) {
                Notification::make()
                    ->title('Kapasitas belum lengkap')
                    ->body('Kapasitas untuk tanggal berikut belum terisi: ' . implode(', ', $missingDates) . '. Muat ulang halaman lalu isi kapasitas semua tanggal kerja sebelum konfirmasi.')
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt();
            }

            $fullDates = Booking::fullDatesInRange(
                (int) $data['store_id'],
                Carbon::parse($data['preferred_date']),
                $durationDays,
                $capacityByDate,
                excludeBookingId: $this->record->id,
            );

            if (! empty($fullDates)) {
                Notification::make()
                    ->title('Kapasitas instalasi penuh')
                    ->body('Tanggal berikut sudah mencapai kapasitas maksimal toko: ' . implode(', ', $fullDates) . '. Pilih tanggal lain, atau ubah status booking lain yang bentrok dulu.')
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt();
            }
        }

        return $data;
    }

    /**
     * Diff watcher lama (ditangkap mutateFormDataBeforeSave() sebelum
     * sinkron relasi) vs watcher setelah disimpan — cuma yang BENAR-BENAR
     * BARU yang dikirimi email, watcher yang sudah lama ada tidak perlu
     * dapat notifikasi ulang tiap kali booking-nya diedit untuk hal lain.
     */
    protected function afterSave(): void
    {
        $newWatcherIds = $this->record->watchers()->pluck('users.id')->all();
        $addedIds = array_diff($newWatcherIds, $this->existingWatcherIds);

        BookingResource::notifyNewWatchers($this->record, $addedIds, auth()->user()?->name ?? 'Admin');
    }
}

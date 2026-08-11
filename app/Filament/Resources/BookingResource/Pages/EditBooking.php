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
            $fullDates = Booking::fullDatesInRange(
                (int) $data['store_id'],
                Carbon::parse($data['preferred_date']),
                max(1, (int) ($data['duration_days'] ?? 1)),
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

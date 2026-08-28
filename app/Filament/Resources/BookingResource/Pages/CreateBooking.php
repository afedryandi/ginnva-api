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
     * pernah ditolak). capacities (kapasitas per tanggal) juga ditangkap
     * & dibuang di sini sekalian karena bukan kolom di tabel bookings.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $capacityByDate = collect($data['capacities'] ?? [])
            ->filter(fn ($row) => ! empty($row['date']))
            ->mapWithKeys(fn ($row) => [Carbon::parse($row['date'])->toDateString() => max(1, (int) ($row['capacity'] ?? 1))])
            ->all();
        unset($data['capacities']);

        if (($data['status'] ?? null) === 'confirmed') {
            $durationDays = max(1, (int) ($data['duration_days'] ?? 1));

            // Sama pengaman dengan EditBooking — lihat komentar di sana
            // untuk penjelasan bug yang ditutup ini.
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
            );

            if (! empty($fullDates)) {
                Notification::make()
                    ->title('Kapasitas instalasi penuh')
                    ->body('Tanggal berikut sudah mencapai kapasitas maksimal toko: ' . implode(', ', $fullDates) . '. Pilih tanggal lain, atau simpan dulu sebagai "Menunggu Konfirmasi" sampai ada slot yang kosong.')
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

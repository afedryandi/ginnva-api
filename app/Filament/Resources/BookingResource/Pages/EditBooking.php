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
     * Validasi kapasitas DIJALANKAN DI SINI (bukan di beforeSave()) supaya
     * kerja langsung dari parameter $data yang dijamin akurat (nilai yang
     * BARU DISUBMIT staff) — SEBELUMNYA pakai $this->data di beforeSave(),
     * yang ternyata TIDAK bisa diandalkan mencerminkan submit terbaru
     * (bug: validasi selalu ke-skip diam-diam, booking 'confirmed' yang
     * bentrok kapasitas tetap lolos tersimpan tanpa pernah ditolak).
     * capacity_per_day juga ditangkap & dibuang di sini sekalian karena
     * bukan kolom di tabel bookings. Record yang sedang diedit
     * dikecualikan dari hitungan kapasitas (bukan "nambah 1 booking baru",
     * cuma mengevaluasi ulang dirinya sendiri).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $capacityPerDay = max(1, (int) ($data['capacity_per_day'] ?? 3));
        unset($data['capacity_per_day']);

        if (($data['status'] ?? null) === 'confirmed') {
            $fullDates = Booking::fullDatesInRange(
                (int) $data['store_id'],
                Carbon::parse($data['preferred_date']),
                max(1, (int) ($data['duration_days'] ?? 1)),
                $capacityPerDay,
                $this->record->id,
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
}

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

    // Lihat komentar sama di CreateBooking — capacity_per_day bukan
    // kolom bookings, ditangkap di mutateFormDataBeforeSave() lalu dibuang
    // sebelum $data sampai ke update().
    private int $capacityPerDay = 3;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->capacityPerDay = max(1, (int) ($data['capacity_per_day'] ?? 3));
        unset($data['capacity_per_day']);

        return $data;
    }

    /**
     * Sama seperti CreateBooking::beforeCreate() — cek kapasitas slot
     * instalasi kalau status akhirnya 'confirmed' (baru diapprove, ATAU
     * booking yang sudah confirmed diedit lagi tanggal/durasinya). Record
     * yang sedang diedit dikecualikan dari hitungan (bukan "nambah 1
     * booking baru", cuma mengevaluasi ulang dirinya sendiri).
     */
    protected function beforeSave(): void
    {
        if (($this->data['status'] ?? null) !== 'confirmed') {
            return;
        }

        $fullDates = Booking::fullDatesInRange(
            (int) $this->data['store_id'],
            Carbon::parse($this->data['preferred_date']),
            max(1, (int) ($this->data['duration_days'] ?? 1)),
            $this->capacityPerDay,
            $this->record->id,
        );

        if (empty($fullDates)) {
            return;
        }

        Notification::make()
            ->title('Kapasitas instalasi penuh')
            ->body('Tanggal berikut sudah mencapai kapasitas maksimal toko: ' . implode(', ', $fullDates) . '. Pilih tanggal lain, atau ubah status booking lain yang bentrok dulu.')
            ->danger()
            ->persistent()
            ->send();

        throw new Halt();
    }
}

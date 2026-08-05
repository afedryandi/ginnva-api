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
     * Cek kapasitas slot instalasi SEBELUM booking dibuat langsung sebagai
     * 'confirmed' (mis. walk-in/WA yang staff approve on the spot) — kalau
     * ada tanggal dalam rentang lama pengerjaannya yang sudah penuh,
     * batalkan simpan supaya tidak lolos over-booking tim instalasi.
     * Booking 'pending' tidak pernah kena cek ini (lihat
     * Booking::confirmedOverlapCount()).
     */
    protected function beforeCreate(): void
    {
        if (($this->data['status'] ?? null) !== 'confirmed') {
            return;
        }

        $fullDates = Booking::fullDatesInRange(
            (int) $this->data['store_id'],
            Carbon::parse($this->data['preferred_date']),
            max(1, (int) ($this->data['duration_days'] ?? 1)),
        );

        if (empty($fullDates)) {
            return;
        }

        Notification::make()
            ->title('Kapasitas instalasi penuh')
            ->body('Tanggal berikut sudah mencapai kapasitas maksimal toko: ' . implode(', ', $fullDates) . '. Pilih tanggal lain, atau simpan dulu sebagai "Menunggu Konfirmasi" sampai ada slot yang kosong.')
            ->danger()
            ->persistent()
            ->send();

        throw new Halt();
    }
}

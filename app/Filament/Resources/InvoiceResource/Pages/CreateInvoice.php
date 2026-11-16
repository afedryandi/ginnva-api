<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Services\InvoiceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    /**
     * Tombol "Buat Invoice" di halaman booking membuka form ini dengan ?booking={id}: pelanggan, referensi booking, dan baris produk
     * terisi dari booking itu. Semuanya hanya isian awal dan tetap bisa diubah manual. Booking di luar toko pengguna diabaikan.
     */
    public function mount(): void
    {
        parent::mount();

        $bookingId = request()->query('booking');

        if (blank($bookingId)) {
            return;
        }

        $booking = \App\Filament\Resources\BookingResource::getEloquentQuery()->find($bookingId);

        if (! $booking) {
            return;
        }

        $data = \App\Services\InvoiceBookingPrefill::dataFor($booking);

        // Staf toko selalu terkunci ke tokonya sendiri (lihat handleRecordCreation).
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            unset($data['store_id']);
        }

        $this->form->fill([...$this->form->getRawState(), ...$data]);

        Notification::make()
            ->title('Terisi dari booking ' . $booking->booking_number)
            ->body('Periksa dan ubah bila perlu. Harga yang belum ada di booking dikosongkan, isi manual lalu tekan Buat.')
            ->info()
            ->send();
    }

    /**
     * Semua tulis-menulis (nomor invoice, item, rekalkulasi total)
     * WAJIB lewat InvoiceService -- lihat catatan di
     * InvoiceResource::form() soal Repeater "items" yang SENGAJA bukan
     * ->relationship() bawaan Filament.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        $data['created_by'] = auth()->id();

        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $data['store_id'] = auth()->user()?->store_id;
        }

        try {
            return app(InvoiceService::class)->create($data, $items, auth()->id());
        } catch (RuntimeException $e) {
            Notification::make()
                ->title('Invoice tidak bisa disimpan')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }
}

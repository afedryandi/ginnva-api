<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\SpkResource;
use App\Models\Booking;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Carbon;

class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    /**
     * Sama tombol dengan ViewBooking -- lihat catatan di sana. Booking
     * bisa dibuka langsung ke halaman Edit (bukan cuma lewat View
     * dulu), jadi tombolnya perlu ada di kedua halaman.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('lihatSpk')
                ->label('Lihat SPK')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('gray')
                ->visible(fn () => $this->record->spk !== null)
                ->url(fn () => $this->record->spk ? SpkResource::getUrl('edit', ['record' => $this->record->spk]) : null),
        ];
    }

    /**
     * Form sudah ->disabled() total di BookingResource::form() begitu
     * status booking 'completed'/'cancelled', tapi tombol Simpan sendiri
     * tidak otomatis ikut hilang — sembunyikan juga di sini supaya tidak
     * ada tombol aktif yang percuma (submit form yang semua fieldnya
     * disabled tetap kirim data lama, jadi secara teknis tidak merusak,
     * tapi membingungkan kalau tombolnya masih terlihat bisa diklik).
     * Ditemukan & diperbaiki 2026-08-29.
     */
    protected function getFormActions(): array
    {
        if (in_array($this->record->status, ['completed', 'cancelled'], true)) {
            return [];
        }

        return parent::getFormActions();
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
     * Record yang sedang diedit dikecualikan dari hitungan kapasitas
     * (bukan "nambah 1 booking baru", cuma mengevaluasi ulang dirinya
     * sendiri).
     *
     * Redesain 2026-09-25 — TIDAK ADA LAGI 'capacities' yang ditangkap/
     * dibuang di sini: kapasitas sekarang dibaca otomatis dari
     * Booking::capacityForDate(), bukan lagi diisi manual staff tiap
     * submit (yang sebelumnya rawan salah/tidak konsisten antar staff).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Field store_id di form ->disabled() untuk non-super-admin —
        // TIDAK ikut ter-submit kecuali eksplisit dehydrated(true), jadi
        // $data['store_id'] tidak ada sama sekali saat Store Manager
        // menyimpan (crash "Undefined array key store_id" begitu status
        // confirmed, baris di bawah butuh nilainya). Kembalikan dari
        // $this->record (toko booking ini sendiri, bukan bisa diubah
        // Store Manager) — sama pola dengan CreateBooking::
        // mutateFormDataBeforeCreate(). Ditemukan lewat testing manual
        // live 2026-08-31.
        $user = auth()->user();
        if ($user && ! $user->isFullAccess()) {
            $data['store_id'] = $this->record->store_id;
        }

        $this->existingWatcherIds = $this->record->watchers()->pluck('users.id')->all();

        if (($data['status'] ?? null) === 'confirmed') {
            $durationDays = max(1, (int) ($data['duration_days'] ?? 1));

            $fullDates = Booking::fullDatesInRange(
                (int) $data['store_id'],
                Carbon::parse($data['preferred_date']),
                $durationDays,
                excludeBookingId: $this->record->id,
            );

            if (! empty($fullDates)) {
                Notification::make()
                    ->title('Kapasitas instalasi penuh')
                    ->body('Tanggal berikut sudah mencapai kapasitas maksimal toko: ' . implode(', ', $fullDates) . '. Pilih tanggal lain, sesuaikan kapasitas lewat Kalender Kapasitas, atau ubah status booking lain yang bentrok dulu.')
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

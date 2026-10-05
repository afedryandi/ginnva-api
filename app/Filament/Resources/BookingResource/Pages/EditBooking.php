<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\BookingResource\Pages\Concerns\HasRescheduleRequestActions;
use App\Filament\Resources\SpkResource;
use App\Models\Booking;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EditBooking extends EditRecord
{
    use HasRescheduleRequestActions;

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
            ...$this->rescheduleRequestActions(),
            ...$this->cancellationRequestActions(),
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
     * Cek kapasitas ULANG di dalam transaction + lock toko (diperbaiki
     * 2026-10-02) -- pengecekan di mutateFormDataBeforeSave() di atas
     * berjalan terpisah dari penyimpanan tanpa lock, jadi dua staff yang
     * menyimpan booking confirmed berbeda bersamaan bisa sama-sama lolos.
     * Cek di atas dipertahankan untuk umpan balik cepat; ini penjaga
     * yang sebenarnya.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return $this->updateInTransaction($record, $data);
        } catch (\DomainException $e) {
            // Status booking sudah berubah dari jalur lain (mis. mobile) sejak
            // form ini dibuka -- tampilkan pesan, bukan halaman error 500.
            Notification::make()
                ->title('Booking tidak bisa disimpan')
                ->body($e->getMessage() . ' Muat ulang halaman untuk melihat status terbaru.')
                ->danger()
                ->persistent()
                ->send();

            throw new Halt();
        }
    }

    private function updateInTransaction(Model $record, array $data): Model
    {
        // Bukan kolom booking -- diambil dulu supaya tidak ikut ter-update.
        $rescheduleReason = $data['reschedule_reason'] ?? null;
        $formVersion = $data['form_version'] ?? null;
        unset($data['reschedule_reason'], $data['form_version']);

        return DB::transaction(function () use ($record, $data, $rescheduleReason) {
            // Kunci baris booking DULU, baru toko -- urutan sama dengan jalur
            // mobile (confirm/reschedule) supaya tidak deadlock. Hasil lock
            // DIPAKAI (2026-10-03): kalau status/tanggal sudah diubah jalur
            // lain sejak form dibuka, simpan ditolak supaya tidak menimpa.
            $fresh = Booking::whereKey($record->id)->lockForUpdate()->first();

            // Token versi (updated_at saat form DIBUKA) -- membandingkan dengan
            // $record tidak berguna karena Livewire memuat ulang $record tiap
            // request (2026-10-03).
            $stale = $formVersion !== null && (int) $formVersion !== (int) $fresh->updated_at?->timestamp;

            if ($stale
                || $fresh->status !== $record->status
                || $fresh->preferred_date?->toDateString() !== $record->preferred_date?->toDateString()) {
                Notification::make()
                    ->title('Booking sudah berubah')
                    ->body('Status atau jadwal booking ini baru saja diubah dari tempat lain. Muat ulang halaman lalu ulangi perubahan Anda.')
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt();
            }

            $newDate = Carbon::parse($data['preferred_date'] ?? $fresh->preferred_date)->startOfDay();
            $dateChanged = ! $newDate->equalTo($fresh->preferred_date->copy()->startOfDay());

            // Ganti tanggal lewat form memakai aturan jadwal ulang yang SAMA
            // dengan mobile (toko tutup/diblokir, pengerjaan sudah mulai,
            // tanggal lampau, kapasitas, sinkron maintenance, tutup pengajuan
            // customer, push) -- 2026-10-03.
            // Booking selesai/batal: tanggal tidak boleh diubah sama sekali.
            if ($dateChanged && in_array($fresh->status, ['completed', 'cancelled'], true)) {
                Notification::make()
                    ->title('Tanggal tidak bisa diubah')
                    ->body('Booking yang sudah selesai atau dibatalkan tidak bisa dijadwal ulang.')
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt();
            }

            if ($dateChanged && $fresh->status === 'confirmed' && blank($rescheduleReason)) {
                Notification::make()
                    ->title('Alasan pindah jadwal wajib diisi')
                    ->body('Booking yang sudah dikonfirmasi: isi "Alasan Pindah Jadwal", akan dikirim ke customer.')
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt();
            }

            if ($dateChanged && in_array($fresh->status, ['pending', 'confirmed'], true)) {
                try {
                    app(\App\Services\BookingRescheduleService::class)->apply($fresh, $newDate, auth()->id(), null, $rescheduleReason);
                } catch (\RuntimeException $e) {
                    Notification::make()
                        ->title('Jadwal tidak bisa diubah')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    throw new Halt();
                }
                $record->refresh();
            }

            $becomesConfirmed = ($data['status'] ?? null) === 'confirmed'
                && ($fresh->status !== 'confirmed' || $dateChanged);

            // Tanggal lampau tidak boleh dikonfirmasi (sama dengan jalur mobile).
            if ($becomesConfirmed && $newDate->lt(today())) {
                Notification::make()
                    ->title('Tanggal sudah lewat')
                    ->body('Booking dengan tanggal yang sudah lewat tidak bisa dikonfirmasi. Ubah tanggalnya dulu.')
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt();
            }

            if (($data['status'] ?? null) === 'confirmed') {
                $fullDates = Booking::fullDatesInRangeLocked(
                    (int) ($data['store_id'] ?? $record->store_id),
                    Carbon::parse($data['preferred_date'] ?? $record->preferred_date),
                    max(1, (int) ($data['duration_days'] ?? $record->duration_days ?? 1)),
                    excludeBookingId: $record->id,
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

            return parent::handleRecordUpdate($record, $data);
        });
    }

    /**
     * Diff watcher lama (ditangkap mutateFormDataBeforeSave() sebelum
     * sinkron relasi) vs watcher setelah disimpan — cuma yang BENAR-BENAR
     * BARU yang dikirimi email, watcher yang sudah lama ada tidak perlu
     * dapat notifikasi ulang tiap kali booking-nya diedit untuk hal lain.
     */
    protected function afterSave(): void
    {
        // Halaman Edit tetap terbuka setelah simpan: token versi harus ikut
        // diperbarui, kalau tidak simpan berikutnya ditolak "sudah berubah".
        $this->record->refresh();
        $this->data['form_version'] = $this->record->updated_at?->timestamp;

        $newWatcherIds = $this->record->watchers()->pluck('users.id')->all();
        $addedIds = array_diff($newWatcherIds, $this->existingWatcherIds);

        BookingResource::notifyNewWatchers($this->record, $addedIds, auth()->user()?->name ?? 'Admin');
    }
}

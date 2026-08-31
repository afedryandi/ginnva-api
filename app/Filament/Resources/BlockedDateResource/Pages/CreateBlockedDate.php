<?php

namespace App\Filament\Resources\BlockedDateResource\Pages;

use App\Filament\Resources\BlockedDateResource;
use App\Models\BlockedDate;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBlockedDate extends CreateRecord
{
    protected static string $resource = BlockedDateResource::class;

    // Dipakai buat pesan notifikasi setelah create (lihat
    // getCreatedNotification()) — diisi di handleRecordCreation().
    private int $createdCount = 1;

    /**
     * Field store_id di form di-disable() untuk non-super-admin (cuma
     * lihat, tidak bisa ganti toko) — tapi field disabled() di Filament
     * TIDAK ikut ter-submit kecuali eksplisit dehydrated(true). Tanpa
     * pengaman ini, store_id akan kosong saat Store Manager submit
     * (store_id NOT NULL di database), jadi create-nya selalu gagal.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $data['store_id'] = $user->store_id;
        }

        return $data;
    }

    /**
     * Override total — bukan cuma 1 baris lagi kalau 'date_end' diisi.
     * SEBELUMNYA staff submit form satu-satu per tanggal untuk blokir
     * multi-hari — lihat audit modul Tanggal Tidak Tersedia 2026-08-27.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $rawEnd = $data['date_end'] ?? null;
        unset($data['date_end']); // bukan kolom di tabel blocked_dates

        $start = Carbon::parse($data['date']);
        $end = $rawEnd ? Carbon::parse($rawEnd) : $start->copy();

        if ($end->lt($start)) {
            // Key HARUS 'data.date_end', bukan 'date_end' polos — field
            // Filament terikat ke state Livewire di path 'data.{field}'.
            // Key mentah tersimpan di error bag tapi tidak ada elemen di
            // halaman yang @error() ke situ, jadi pesannya HILANG TOTAL
            // (submit ditolak diam-diam, staff cuma lihat form tidak
            // berubah tanpa notifikasi apa pun). Ditemukan & diperbaiki
            // 2026-08-29 lewat testing manual.
            throw ValidationException::withMessages([
                'data.date_end' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            ]);
        }

        $dates = [];
        $day = $start->copy();
        while ($day->lte($end)) {
            $dates[] = $day->toDateString();
            $day->addDay();
        }

        // Cek dupe SEMUA tanggal dalam rentang dulu sebelum insert apa
        // pun — supaya tidak insert separuh rentang lalu gagal di
        // tengah jalan karena satu tanggal sudah diblokir sebelumnya
        // (partial state yang membingungkan staff).
        $existing = BlockedDate::where('store_id', $data['store_id'])
            ->whereIn('date', $dates)
            ->pluck('date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        if (! empty($existing)) {
            // Sama seperti di atas — key HARUS 'data.date', bukan 'date'
            // polos, kalau tidak pesan ini hilang total tanpa notifikasi.
            throw ValidationException::withMessages([
                'data.date' => 'Tanggal berikut sudah diblokir untuk toko ini: ' . implode(', ', $existing),
            ]);
        }

        $this->createdCount = count($dates);

        return DB::transaction(function () use ($dates, $data) {
            $records = collect($dates)->map(fn (string $date) => BlockedDate::create([
                'store_id' => $data['store_id'],
                'date'     => $date,
                'reason'   => $data['reason'] ?? null,
            ]));

            // Filament butuh 1 Model dikembalikan (dipakai redirect/
            // notifikasi bawaan) — baris pertama cukup mewakili, isi
            // rentang lengkap sudah ditampilkan di notifikasi kustom
            // (lihat getCreatedNotification()).
            return $records->first();
        });
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title($this->createdCount > 1
                ? "{$this->createdCount} tanggal berhasil diblokir."
                : 'Tanggal berhasil diblokir.');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

<?php

namespace App\Observers;

use App\Models\Spk;
use App\Services\PushNotificationService;

/**
 * GAP DIPERBAIKI 2026-09-25 (audit SPK) -- SEBELUMNYA tidak ada
 * notifikasi apa pun untuk siklus hidup SPK (dibuat, checkout), padahal
 * modul lain yang sebanding (Booking, Quotation) sudah punya ini lewat
 * observer masing-masing. Pola SAMA PERSIS dengan
 * BookingObserver::created() -- sendToStoreStaff() sudah menangani
 * sendiri fallback ke super_admin kalau toko belum punya staff
 * terdaftar, tidak perlu diulang manual di sini.
 */
class SpkObserver
{
    public function __construct(private PushNotificationService $push)
    {
    }

    public function created(Spk $spk): void
    {
        if (! $spk->store_id) {
            return;
        }

        $this->push->sendToStoreStaff(
            $spk->store_id,
            'SPK Baru Dibuat',
            "SPK #{$spk->spk_number} untuk {$spk->customer_name} sudah dibuat.",
            [
                'type'   => 'spk_new',
                'spk_id' => $spk->id,
                'route'  => "/staff/spks/{$spk->id}",
            ]
        );
    }

    /**
     * "Kendaraan keluar" (checked_out_at terisi) -- momen SPK ditandai
     * selesai secara operasional. Cuma dikirim pas transisi null -> terisi
     * (bukan tiap kali SPK yang sudah checkout diedit ulang), sama pola
     * dengan assertBatchTrackingSatisfied() di SpkService.
     */
    public function updated(Spk $spk): void
    {
        if (! $spk->wasChanged('checked_out_at') || ! $spk->checked_out_at || ! $spk->store_id) {
            return;
        }

        $this->push->sendToStoreStaff(
            $spk->store_id,
            'SPK Selesai',
            "SPK #{$spk->spk_number} untuk {$spk->customer_name} sudah ditandai kendaraan keluar.",
            [
                'type'   => 'spk_checked_out',
                'spk_id' => $spk->id,
                'route'  => "/staff/spks/{$spk->id}",
            ]
        );
    }
}

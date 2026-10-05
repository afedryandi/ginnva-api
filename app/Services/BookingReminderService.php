<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Pengingat H-1 booking confirmed (customer + installer yang ditugaskan).
 * Dipakai command harian (08:00 & 16:00) DAN langsung dari BookingObserver
 * kalau booking baru dikonfirmasi/dipindah ke "besok" setelah jam 16:00
 * (tidak ada run terjadwal lagi sebelum besok).
 */
class BookingReminderService
{
    public function __construct(private PushNotificationService $push)
    {
    }

    /**
     * Kirim H-1 untuk satu booking & tandai terkirim. Return true kalau
     * terkirim (false kalau tidak memenuhi syarat atau push gagal).
     */
    public function sendH1(Booking $b): bool
    {
        if ($b->status !== 'confirmed' || $b->h1_reminder_sent_at !== null) {
            return false;
        }

        try {
            if ($b->customer_id) {
                $this->push->sendToCustomer(
                    $b->customer_id,
                    'Pengingat Jadwal Besok',
                    "Besok jadwal booking #{$b->booking_number} Anda. Jika berhalangan, segera hubungi toko.",
                    ['type' => 'booking_reminder_h1', 'booking_id' => $b->id, 'route' => "/booking/{$b->id}/chat"]
                );
            }

            $installerIds = $b->installers()->pluck('users.id');
            if ($installerIds->isNotEmpty()) {
                $this->push->sendToUsers(
                    $installerIds,
                    'Jadwal Instalasi Besok',
                    "Besok ada jadwal booking #{$b->booking_number}.",
                    ['type' => 'booking_reminder_h1', 'booking_id' => $b->id, 'route' => "/staff/bookings/{$b->id}"]
                );
            }
        } catch (\Throwable $e) {
            report($e);

            return false; // jangan tandai terkirim kalau push gagal
        }

        DB::table('bookings')->where('id', $b->id)->update(['h1_reminder_sent_at' => now()]);

        return true;
    }
}

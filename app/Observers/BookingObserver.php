<?php

namespace App\Observers;

use App\Mail\NewBookingMail;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BookingObserver
{
    /**
     * Kirim notifikasi email ke admin toko (regional_admin) yang terkait
     * saat ada booking baru masuk — baik dari app, WhatsApp manual, maupun
     * walk-in yang diinput admin sendiri.
     *
     * Kalau toko tersebut belum punya admin terdaftar, fallback kirim ke
     * semua super_admin supaya booking tidak pernah terlewat tanpa notif.
     */
    public function created(Booking $booking): void
    {
        $recipients = User::role('regional_admin')
            ->where('store_id', $booking->store_id)
            ->pluck('email');

        if ($recipients->isEmpty()) {
            $recipients = User::role('super_admin')->pluck('email');
        }

        if ($recipients->isEmpty()) return;

        try {
            Mail::to($recipients->all())->send(new NewBookingMail($booking));
        } catch (\Exception $e) {
            // Jangan sampai kegagalan kirim email menggagalkan proses
            // pembuatan booking itu sendiri — cukup dicatat di log.
            Log::error('Gagal mengirim notifikasi email booking baru', [
                'booking_id' => $booking->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}

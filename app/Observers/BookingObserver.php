<?php

namespace App\Observers;

use App\Mail\NewBookingMail;
use App\Models\Booking;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BookingObserver
{
    public function __construct(private PushNotificationService $push)
    {
    }

    /**
     * Kirim notifikasi email + push ke staff toko (role apa pun SELAIN
     * installer/partner — role divisi seperti Store Manager, dst) yang
     * terkait saat ada booking baru masuk — baik dari app, WhatsApp
     * manual, maupun walk-in yang diinput admin sendiri.
     *
     * Kalau toko tersebut belum punya staff terdaftar, fallback kirim ke
     * semua yang isFullAccess() (super_admin/direksi) supaya booking
     * tidak pernah terlewat tanpa notif.
     */
    public function created(Booking $booking): void
    {
        $staff = User::where('store_id', $booking->store_id)
            ->get()
            ->filter(fn (User $u) => $u->isRestrictedStaff());

        $recipients = $staff->pluck('email');

        if ($recipients->isEmpty()) {
            $recipients = User::role(['super_admin', 'direksi'])->pluck('email');
        }

        if ($recipients->isNotEmpty()) {
            try {
                Mail::to($recipients->all())->send(new NewBookingMail($booking));
            } catch (\Exception $e) {
                // Jangan sampai kegagalan kirim email menggagalkan proses
                // pembuatan booking itu sendiri — cukup dicatat di log +
                // dilaporkan ke Sentry (audit framework 2026-09-14,
                // "Monitoring & alerting error production").
                Log::error('Gagal mengirim notifikasi email booking baru', [
                    'booking_id' => $booking->id,
                    'error'      => $e->getMessage(),
                ]);
                report($e);
            }
        }

        if ($booking->store_id) {
            $this->push->sendToStoreStaff(
                $booking->store_id,
                'Booking Baru',
                "Booking baru #{$booking->booking_number} masuk untuk {$booking->service_type}.",
                [
                    'type'       => 'booking_new',
                    'booking_id' => $booking->id,
                    'route'      => "/staff/bookings/{$booking->id}",
                ]
            );
        }
    }

    /**
     * Customer SEBELUMNYA tidak pernah diberi tahu sama sekali begitu
     * booking-nya di-approve/dibatalkan — cuma tahu kalau buka app manual,
     * atau kebetulan staff sudah mulai kirim pesan tahap pertama (yang
     * baru memicu notif lewat BookingMessageObserver). Sekarang perubahan
     * status itu sendiri yang memicu notif, terlepas dari ada pesan chat
     * atau tidak. Cuma peduli booking dari app (customer_id terisi) —
     * booking walk-in/WA manual tanpa akun customer tidak punya siapa pun
     * untuk dikirimi notif.
     */
    public function updated(Booking $booking): void
    {
        if (! $booking->customer_id) {
            return;
        }

        $tanggal = $booking->preferred_date?->format('d M Y');

        if ($booking->wasChanged('status')) {
            match ($booking->status) {
                'confirmed' => $this->push->sendToCustomer(
                    $booking->customer_id,
                    'Booking Dikonfirmasi',
                    "Booking #{$booking->booking_number} Anda sudah dikonfirmasi toko untuk tanggal {$tanggal}.",
                    [
                        'type'       => 'booking_confirmed',
                        'booking_id' => $booking->id,
                        'route'      => "/booking/{$booking->id}/chat",
                    ]
                ),
                'cancelled' => $this->push->sendToCustomer(
                    $booking->customer_id,
                    'Booking Dibatalkan',
                    "Booking #{$booking->booking_number} Anda ({$tanggal}) telah dibatalkan. Hubungi toko untuk info lebih lanjut.",
                    [
                        'type'       => 'booking_cancelled',
                        'booking_id' => $booking->id,
                        'route'      => "/booking/{$booking->id}/chat",
                    ]
                ),
                default => null,
            };
        }

        // Reschedule (audit modul Booking Instalasi 2026-09-25, gap
        // "standar enterprise"): SEBELUMNYA mengubah preferred_date pada
        // booking yang sudah 'confirmed' TIDAK memicu notifikasi apa pun
        // — customer cuma tahu kalau kebetulan buka app atau dihubungi
        // manual. 'confirmed' saja yang relevan diberi tahu — booking
        // 'pending' belum pasti tanggalnya di mata customer (bisa masih
        // berubah saat triase), dan 'completed'/'cancelled' sudah final
        // (tidak masuk akal notif reschedule utk booking yang sudah
        // kelar/batal). SENGAJA method notifikasi TERPISAH (bukan ditumpuk
        // ke match status di atas) — status TIDAK berubah di sini, cuma
        // tanggalnya, jadi keduanya independen dan bisa terjadi sekaligus
        // dalam 1 update (mis. staff ganti tanggal SEKALIGUS approve).
        if ($booking->wasChanged('preferred_date') && $booking->status === 'confirmed') {
            $tanggalLama = $booking->getOriginal('preferred_date');
            $tanggalLama = $tanggalLama ? \Illuminate\Support\Carbon::parse($tanggalLama)->format('d M Y') : null;

            $this->push->sendToCustomer(
                $booking->customer_id,
                'Jadwal Booking Berubah',
                $tanggalLama
                    ? "Booking #{$booking->booking_number} Anda dijadwal ulang dari {$tanggalLama} ke {$tanggal}."
                    : "Booking #{$booking->booking_number} Anda dijadwal ulang ke {$tanggal}.",
                [
                    'type'       => 'booking_rescheduled',
                    'booking_id' => $booking->id,
                    'route'      => "/booking/{$booking->id}/chat",
                ]
            );
        }
    }
}

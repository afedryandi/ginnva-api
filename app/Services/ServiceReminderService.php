<?php

namespace App\Services;

use App\Mail\ServiceReminderMail;
use App\Models\Booking;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Kirim reminder servis/maintenance berkala untuk 1 booking lewat 3 kanal
 * independen (WhatsApp+Push+Email) — dipakai oleh 2 pemicu:
 * 1. Command terjadwal `reminders:send-service` (otomatis, tanggal
 *    ditentukan manual oleh store manager lewat `next_service_reminder_at`).
 * 2. Aksi manual "Kirim Pengingat Maintenance" di Filament (BookingResource)
 *    & mobile app staff — store manager kirim kapan saja tanpa menunggu
 *    tanggal terjadwal, mis. begitu instalasi PPF/Kaca Film selesai.
 */
class ServiceReminderService
{
    public function __construct(
        private readonly PushNotificationService $push,
        private readonly WhatsAppService $whatsapp,
    ) {
    }

    /**
     * @param bool $force Lewati pengecekan `service_reminder_sent_at` —
     *                     dipakai trigger manual supaya staff tetap bisa
     *                     kirim ulang kalau customer minta diingatkan lagi.
     * @return array{push: bool, email: bool, whatsapp: bool}
     */
    public function sendFor(Booking $booking, bool $force = false): array
    {
        $results = ['push' => false, 'email' => false, 'whatsapp' => false];

        $title = 'Waktunya Servis Berkala';
        $body = "Booking {$booking->booking_number} — sudah waktunya cek kondisi {$booking->service_type} Anda di toko Ginnva.";

        if ($booking->customer_id) {
            try {
                $this->push->sendToCustomer($booking->customer_id, $title, $body, [
                    'type'       => 'service_reminder',
                    'booking_id' => $booking->id,
                    'route'      => '/(tabs)/stores',
                ]);
                $results['push'] = true;
            } catch (\Throwable $e) {
                Log::error('[ServiceReminder] Push gagal: ' . $e->getMessage(), ['booking_id' => $booking->id]);
            }
        }

        $email = $booking->customer?->email;
        if ($email) {
            try {
                Mail::to($email)->send(new ServiceReminderMail($booking));
                $results['email'] = true;
            } catch (\Throwable $e) {
                Log::error('[ServiceReminder] Email gagal: ' . $e->getMessage(), ['booking_id' => $booking->id]);
            }
        }

        $phone = $booking->customer?->phone_number ?? $booking->phone_number;
        if ($phone) {
            $results['whatsapp'] = $this->whatsapp->sendTemplate($phone, [
                $booking->customer?->name ?? $booking->customer_name ?? 'Customer',
                $booking->service_type,
                $booking->booking_number,
            ]);
        }

        // force=true (trigger manual) sengaja tetap menimpa
        // service_reminder_sent_at biar kolom "Reminder Servis" di Filament
        // selalu menunjukkan pengiriman TERAKHIR, bukan cuma pengiriman
        // otomatis pertama.
        if ($force || ! $booking->service_reminder_sent_at) {
            $booking->update(['service_reminder_sent_at' => now()]);
        }

        return $results;
    }
}

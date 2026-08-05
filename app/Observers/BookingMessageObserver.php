<?php

namespace App\Observers;

use App\Models\Booking;
use App\Models\BookingMessage;
use App\Services\PushNotificationService;

class BookingMessageObserver
{
    public function __construct(private PushNotificationService $push)
    {
    }

    /**
     * Setiap kali admin toko mengirim pesan (teks, foto progress, atau
     * update tahap), customer perlu tahu — baik lewat push notification
     * maupun update tahap terkini di kartu ringkasan beranda.
     */
    public function created(BookingMessage $message): void
    {
        $booking = $message->booking;
        if (! $booking) {
            return;
        }

        if ($message->sender_type === 'admin') {
            // Update tahap terkini di booking (dipakai untuk progress bar
            // di beranda mobile app tanpa perlu query booking_messages).
            // Booking dengan 2 produk (Kaca Film + PPF) punya track
            // terpisah — lihat Booking::stageColumnFor().
            if ($message->type === 'stage' && $message->stage) {
                $bothProducts = $booking->product_kaca_film && $booking->product_ppf;
                $column = Booking::stageColumnFor($bothProducts, $message->stage);
                $booking->update([$column => $message->stage]);
            }

            if ($booking->customer_id) {
                [$title, $body] = $this->buildNotificationText($message);

                $this->push->sendToCustomer($booking->customer_id, $title, $body, [
                    'type'       => 'booking_message',
                    'booking_id' => $booking->id,
                    'route'      => "/booking/{$booking->id}/chat",
                ]);
            }

            return;
        }

        if ($message->sender_type === 'customer' && $booking->store_id) {
            $title = 'Pesan Baru dari Customer';
            $body  = \Illuminate\Support\Str::limit($message->body ?? 'Ada pesan baru masuk.', 100);
            // Type dibedakan dari punya customer ('booking_message') di atas
            // — sama-sama chat booking tapi tujuan layarnya beda (app
            // customer vs app staff), jadi type-nya juga harus beda supaya
            // tidak ambigu di sisi mobile.
            $data  = [
                'type'       => 'staff_booking_message',
                'booking_id' => $booking->id,
                'route'      => "/staff/bookings/{$booking->id}",
            ];

            $this->push->sendToStoreStaff($booking->store_id, $title, $body, $data);
            $this->push->sendToBookingWatchers($booking, $title, $body, $data);
        }
    }

    private function buildNotificationText(BookingMessage $message): array
    {
        if ($message->type === 'stage' && $message->stage) {
            $stageLabel = BookingMessage::allStages()[$message->stage] ?? $message->stage;

            return [
                'Update Progress Instalasi',
                "Booking Anda memasuki tahap: {$stageLabel}",
            ];
        }

        if ($message->type === 'photo') {
            return [
                'Foto Baru dari Toko',
                'Toko mengirimkan foto terbaru untuk booking Anda.',
            ];
        }

        return [
            'Pesan Baru dari Toko',
            $message->body ? \Illuminate\Support\Str::limit($message->body, 100) : 'Anda mendapat pesan baru.',
        ];
    }
}

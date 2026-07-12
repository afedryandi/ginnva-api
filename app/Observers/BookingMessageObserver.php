<?php

namespace App\Observers;

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
            if ($message->type === 'stage' && $message->stage) {
                $booking->update(['current_stage' => $message->stage]);
            }

            if ($booking->customer_id) {
                [$title, $body] = $this->buildNotificationText($message);

                $this->push->sendToCustomer($booking->customer_id, $title, $body, [
                    'type'       => 'booking_message',
                    'booking_id' => $booking->id,
                ]);
            }

            return;
        }

        if ($message->sender_type === 'customer' && $booking->store_id) {
            $this->push->sendToStoreStaff(
                $booking->store_id,
                'Pesan Baru dari Customer',
                \Illuminate\Support\Str::limit($message->body ?? 'Ada pesan baru masuk.', 100),
                [
                    'type'       => 'booking_message',
                    'booking_id' => $booking->id,
                ]
            );
        }
    }

    private function buildNotificationText(BookingMessage $message): array
    {
        if ($message->type === 'stage' && $message->stage) {
            $stageLabel = BookingMessage::STAGES[$message->stage] ?? $message->stage;

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

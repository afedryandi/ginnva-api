<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewBookingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking)
    {
    }

    public function build()
    {
        $b = $this->booking->loadMissing('customer', 'store');

        $customerName = $b->customer_name ?? $b->customer?->name ?? 'Tanpa Nama';
        $phoneNumber  = $b->phone_number ?? $b->customer?->phone_number ?? '-';

        $sourceLabel = match ($b->source) {
            'app'       => 'Aplikasi Ginnva',
            'whatsapp'  => 'WhatsApp',
            'walk_in'   => 'Walk-in',
            default     => $b->source ?? '-',
        };

        return $this->subject("Booking Baru — {$b->booking_number}")
            ->view('emails.new_booking')
            ->with([
                'bookingNumber' => $b->booking_number,
                'storeName'     => $b->store?->name ?? '-',
                'customerName'  => $customerName,
                'phoneNumber'   => $phoneNumber,
                'serviceType'   => $b->service_type,
                'preferredDate' => $b->preferred_date?->translatedFormat('d F Y'),
                'preferredTime' => $b->preferred_time,
                'source'        => $sourceLabel,
                'notes'         => $b->notes,
            ]);
    }
}

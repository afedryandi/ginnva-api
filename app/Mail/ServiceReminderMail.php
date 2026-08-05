<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ServiceReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking)
    {
    }

    public function build()
    {
        $b = $this->booking;

        return $this->subject('Waktunya Servis Berkala — Ginnva')
            ->view('emails.service_reminder')
            ->with([
                'customerName' => $b->customer?->name ?? $b->customer_name,
                'serviceType'  => $b->service_type,
                'bookingNumber' => $b->booking_number,
                'storeName'    => $b->store?->name,
            ]);
    }
}

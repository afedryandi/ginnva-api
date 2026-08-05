<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BookingWatcherAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking, public string $assignedByName)
    {
    }

    public function build()
    {
        $b = $this->booking->loadMissing('customer', 'store');

        $customerName = $b->customer_name ?? $b->customer?->name ?? 'Tanpa Nama';

        return $this->subject("Anda Ditugaskan Memantau Booking — {$b->booking_number}")
            ->view('emails.booking_watcher_assigned')
            ->with([
                'bookingNumber' => $b->booking_number,
                'storeName'     => $b->store?->name ?? '-',
                'customerName'  => $customerName,
                'serviceType'   => $b->service_type,
                'assignedByName' => $this->assignedByName,
            ]);
    }
}

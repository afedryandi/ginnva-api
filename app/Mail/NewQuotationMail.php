<?php

namespace App\Mail;

use App\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewQuotationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Quotation $quotation)
    {
    }

    public function build()
    {
        $q = $this->quotation->loadMissing('vehicle', 'store', 'items.filmProduct');

        $vehicleLabel = $q->vehicle
            ? trim("{$q->vehicle->brand} {$q->vehicle->model} {$q->vehicle->variant}")
            : '-';

        $productNames = $q->items->map(fn ($item) => $item->filmProduct?->name)
            ->filter()
            ->implode(', ');

        return $this->subject("Quotation Baru — {$q->quotation_number}")
            ->view('emails.new_quotation')
            ->with([
                'quotationNumber' => $q->quotation_number,
                'storeName'       => $q->store?->name ?? '-',
                'customerName'    => $q->customer_name,
                'phoneNumber'     => $q->customer_phone,
                'email'           => $q->customer_email,
                'vehicleLabel'    => $vehicleLabel ?: '-',
                'licensePlate'    => $q->license_plate,
                'products'        => $productNames ?: '-',
                // BUKAN 'message' — Laravel SELALU inject variabel $message
                // (object Illuminate\Mail\Message) ke tiap view mail secara
                // otomatis, menimpa key 'message' apa pun yang dikirim lewat
                // ->with(). Itu penyebab error
                // "htmlspecialchars(): ... Illuminate\Mail\Message given"
                // di log. Ditemukan & diperbaiki 2026-08-27.
                'customerNote'    => $q->message,
            ]);
    }
}

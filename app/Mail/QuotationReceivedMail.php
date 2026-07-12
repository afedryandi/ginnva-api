<?php

namespace App\Mail;

use App\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class QuotationReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Quotation $quotation)
    {
    }

    public function build()
    {
        $q = $this->quotation->loadMissing('vehicle', 'items.filmProduct');

        $products = $q->items
            ->map(fn($item) => $item->filmProduct?->name ?? 'Produk Ginnva')
            ->filter()
            ->values()
            ->toArray();

        return $this->subject("Permintaan Penawaran Diterima — {$q->quotation_number}")
            ->view('emails.quotation_received')
            ->with([
                'quotationNumber' => $q->quotation_number,
                'customerName'    => $q->customer_name,
                'customerPhone'   => $q->customer_phone,
                'vehicle'         => $q->vehicle
                    ? "{$q->vehicle->brand} {$q->vehicle->model}"
                    : '-',
                'licensePlate'    => $q->license_plate,
                'products'        => $products,
            ]);
    }
}
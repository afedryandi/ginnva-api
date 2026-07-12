<?php

namespace App\Mail;

use App\Models\Warranty;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WarrantyRegisteredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Warranty $warranty)
    {
    }

    public function build()
    {
        $w = $this->warranty;

        return $this->subject("Garansi Terdaftar — {$w->warranty_code}")
            ->view('emails.warranty_registered')
            ->with([
                'warrantyCode'     => $w->warranty_code,
                'customerName'     => $w->customer_name,
                'productSeries'    => $w->product_series,
                'carType'          => $w->car_type,
                'carPlate'         => $w->car_plate,
                'dealerName'       => $w->dealer_name,
                'installationDate' => Carbon::parse($w->installation_date)
                    ->locale('id')->isoFormat('D MMMM YYYY'),
                'expiryDate'       => Carbon::parse($w->expiry_date)
                    ->locale('id')->isoFormat('D MMMM YYYY'),
            ]);
    }
}
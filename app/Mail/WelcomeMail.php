<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Customer $customer)
    {
    }

    public function build()
    {
        return $this->subject('Selamat datang di Ginnva Shield Indonesia!')
            ->view('emails.welcome')
            ->with([
                'name'  => $this->customer->name,
                'email' => $this->customer->email,
            ]);
    }
}
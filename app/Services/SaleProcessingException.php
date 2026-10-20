<?php

namespace App\Services;

use RuntimeException;

/** Penolakan terstruktur dari BookingSaleProcessor: judul singkat + pesan yang siap ditampilkan ke pengguna (panel maupun HP). */
class SaleProcessingException extends RuntimeException
{
    public function __construct(public readonly string $title, string $message)
    {
        parent::__construct($message);
    }
}

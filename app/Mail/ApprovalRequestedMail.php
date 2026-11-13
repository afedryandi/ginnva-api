<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Email ke direksi saat ada pengajuan baru yang menunggu keputusan (2026-11-13): direksi yang memakai iPhone belum bisa memasang aplikasi
 * Ginnva, jadi email ini yang memberi tahu. Tombol di dalamnya membuka Pusat Persetujuan di panel.
 */
class ApprovalRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $typeLabel,
        public string $who,
        public string $summary,
        public ?string $storeName,
        public string $url,
        public int $pendingCount,
    ) {
    }

    public function build()
    {
        return $this->subject("Persetujuan menunggu: {$this->typeLabel} dari {$this->who}")
            ->view('emails.approval_requested')
            ->with([
                'typeLabel' => $this->typeLabel,
                'who' => $this->who,
                'summary' => $this->summary,
                'storeName' => $this->storeName,
                'url' => $this->url,
                'pendingCount' => $this->pendingCount,
            ]);
    }
}

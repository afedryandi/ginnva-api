<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Isi kotak masuk staf (Notifikasi Fase 1, 2026-10-21): satu baris di tabel notifications Laravel yang SAMA dengan lonceng panel Filament,
 * sehingga panel dan (Fase 2) aplikasi mobile membaca sumber yang sama. Bentuk data kompatibel dengan notifikasi basis data Filament
 * (format "filament"); muatan push (route, params, type, dst.) disimpan di viewData supaya aplikasi mobile bisa membuka layar tujuannya.
 */
class StaffInboxNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $payload  data yang juga dikirim bersama push
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $payload = [],
        public ?string $coalesceKey = null,
        public string $color = 'info',
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return self::format($this->title, $this->body, $this->payload, $this->coalesceKey, $this->color);
    }

    /** @return array<string, mixed> */
    public static function format(string $title, string $body, array $payload, ?string $coalesceKey, string $color): array
    {
        return [
            'format' => 'filament',
            'title' => $title,
            'body' => $body,
            'color' => $color,
            'icon' => null,
            'iconColor' => null,
            'status' => null,
            'duration' => 'persistent',
            'actions' => [],
            'view' => 'filament-notifications::notification',
            'viewData' => ['source' => 'staff_inbox', 'payload' => $payload, 'coalesce_key' => $coalesceKey],
        ];
    }
}

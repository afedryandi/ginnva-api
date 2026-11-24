<?php

namespace App\Notifications\Channels;

use App\Services\WebPushService;
use Illuminate\Notifications\Notification;

/**
 * Saluran notifikasi: meneruskan notifikasi kotak masuk staf ke push web PWA panel (2026-11-24). Notifikasi harus punya toWebPush():
 * array berisi title, body, dan url (opsional). Kegagalan kirim ditangani di WebPushService dan tidak mengganggu saluran lain.
 */
class WebPushChannel
{
    public function __construct(private WebPushService $service)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWebPush')) {
            return;
        }

        $data = $notification->toWebPush($notifiable);

        $this->service->pushTo([$notifiable->getKey()], (string) ($data['title'] ?? 'Ginnva'), (string) ($data['body'] ?? ''), $data['url'] ?? null);
    }
}

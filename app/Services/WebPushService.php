<?php

namespace App\Services;

use App\Models\WebPushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Push web untuk PWA panel admin (2026-11-24): mengirim notifikasi ke perangkat yang berlangganan (iPhone yang memasang panel ke Layar Utama,
 * Chrome, dll.) lewat protokol Web Push + VAPID. Tidak butuh akun pihak ketiga; kuncinya dibuat sendiri (php artisan webpush:keys).
 *
 * Satu jalur kirim: dipakai saluran notifikasi kotak masuk (WebPushChannel) dan tombol uji di halaman Notifikasi di HP. Langganan yang sudah
 * tidak berlaku (404/410) dihapus otomatis. Di lingkungan lokal pengiriman diblokir seperti push aplikasi (PUSH_ALLOW_LOCAL).
 */
class WebPushService
{
    /** @param  (callable(): WebPush)|null  $clientFactory  untuk tes; bawaannya klien nyata dengan kunci VAPID dari konfigurasi */
    public function __construct(private $clientFactory = null)
    {
    }

    public function enabled(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    public function publicKey(): ?string
    {
        return config('services.webpush.public_key') ?: null;
    }

    public function sendingAllowed(): bool
    {
        return PushNotificationService::sendingAllowed();
    }

    public function hasSubscriptions(int $userId): bool
    {
        return WebPushSubscription::where('user_id', $userId)->exists();
    }

    /**
     * Kirim ke semua perangkat milik akun-akun itu.
     *
     * @param  iterable<int|string>  $userIds
     * @return array{sent: int, failed: int, removed: int}
     */
    public function pushTo(iterable $userIds, string $title, string $body, ?string $url = null): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'removed' => 0];

        if (! $this->enabled()) {
            return $result;
        }

        if (! $this->sendingAllowed()) {
            \Illuminate\Support\Facades\Log::info('Push web dilewati: lingkungan lokal (isi PUSH_ALLOW_LOCAL=true untuk menyalakan).', ['title' => $title]);

            return $result;
        }

        $subscriptions = WebPushSubscription::whereIn('user_id', collect($userIds)->filter()->unique()->all())->get();

        if ($subscriptions->isEmpty()) {
            return $result;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url ?: url('/admin'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $client = $this->client();
            $byEndpoint = $subscriptions->keyBy('endpoint');

            foreach ($subscriptions as $subscription) {
                $client->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'publicKey' => $subscription->p256dh,
                        'authToken' => $subscription->auth,
                        'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
                    ]),
                    $payload,
                    ['TTL' => 3600, 'urgency' => 'high']
                );
            }

            foreach ($client->flush() as $report) {
                if ($report->isSuccess()) {
                    $result['sent']++;

                    continue;
                }

                $result['failed']++;

                // 404/410: perangkat sudah mencabut langganan atau aplikasinya dihapus; baris dibuang supaya tidak dicoba lagi.
                if ($report->isSubscriptionExpired()) {
                    $byEndpoint->get($report->getEndpoint())?->delete();
                    $result['removed']++;
                }
            }
        } catch (\Throwable $e) {
            // Gagal kirim tidak boleh menggagalkan proses pemanggil (notifikasi kotak masuk tetap tersimpan).
            report($e);
        }

        return $result;
    }

    private function client(): WebPush
    {
        if ($this->clientFactory) {
            return ($this->clientFactory)();
        }

        return new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject') ?: config('app.url'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ], ['TTL' => 3600], 15);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Langganan push web satu perangkat/browser milik satu akun (PWA panel admin). Endpoint unik per perangkat; dua kali berlangganan dari perangkat
 * yang sama memperbarui baris yang ada (lihat WebPushSubscription::remember).
 */
class WebPushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'content_encoding', 'user_agent', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** Simpan (atau perbarui) langganan; endpoint yang sama dipindahkan ke akun yang sedang login. */
    public static function remember(int $userId, string $endpoint, string $p256dh, string $auth, ?string $encoding = null, ?string $userAgent = null): self
    {
        return static::updateOrCreate(
            ['endpoint_hash' => static::hashEndpoint($endpoint)],
            [
                'user_id' => $userId,
                'endpoint' => $endpoint,
                'p256dh' => $p256dh,
                'auth' => $auth,
                'content_encoding' => $encoding ?: 'aes128gcm',
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'last_used_at' => now(),
            ]
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

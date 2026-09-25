<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Verifikasi Google ID token (hasil "Sign in with Google" di frontend)
 * lewat endpoint tokeninfo Google — pendekatan paling sederhana, tanpa
 * perlu library JWT/JWK untuk verifikasi signature secara lokal.
 */
class GoogleIdTokenVerifier
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    /**
     * Return email yang sudah terverifikasi (lowercase, trimmed), atau
     * null kalau token tidak valid/kedaluwarsa/aud tidak cocok/email
     * belum diverifikasi Google. Sengaja tidak throw — caller (controller)
     * yang memutuskan response HTTP-nya.
     */
    public function verify(string $idToken): ?string
    {
        $response = Http::get(self::TOKENINFO_URL, ['id_token' => $idToken]);

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        $expectedAudience = config('services.google_pricelist.oauth_client_id');
        if (($payload['aud'] ?? null) !== $expectedAudience) {
            return null;
        }

        // Google mengembalikan email_verified sebagai string "true"/"false"
        // di endpoint tokeninfo (bukan boolean asli) — jadi cek keduanya.
        $emailVerified = $payload['email_verified'] ?? null;
        if ($emailVerified !== true && $emailVerified !== 'true') {
            return null;
        }

        $email = trim((string) ($payload['email'] ?? ''));
        if ($email === '') {
            return null;
        }

        return strtolower($email);
    }
}

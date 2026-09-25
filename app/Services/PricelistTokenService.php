<?php

namespace App\Services;

/**
 * Token sesi stateless untuk API Price List Kaca Film — sengaja BUKAN
 * Sanctum/JWT library, karena fitur ini tidak berhubungan dengan sistem
 * auth staff (tabel users) sama sekali. Ditandatangani HMAC pakai APP_KEY,
 * jadi bisa diverifikasi tanpa query database sama sekali.
 *
 * Login pakai 1 akun bersama (username+password di .env, lihat
 * PricelistController::login()), bukan identitas per-orang -- jadi token
 * di sini murni menandakan "sudah login sah", tidak menyimpan siapa.
 */
class PricelistTokenService
{
    private const TTL_SECONDS = 8 * 60 * 60; // 8 jam

    public function issue(): string
    {
        $payload = json_encode([
            'sub' => 'pricelist-shared-account',
            'exp' => time() + self::TTL_SECONDS,
        ]);

        $encodedPayload = $this->base64UrlEncode($payload);
        $signature = $this->sign($encodedPayload);

        return $encodedPayload.'.'.$signature;
    }

    /**
     * Return true kalau token valid (signature cocok & belum
     * kedaluwarsa), false kalau tidak.
     */
    public function verify(string $token): bool
    {
        $lastDot = strrpos($token, '.');
        if ($lastDot === false) {
            return false;
        }

        $encodedPayload = substr($token, 0, $lastDot);
        $signature = substr($token, $lastDot + 1);

        $expectedSignature = $this->sign($encodedPayload);

        if (! hash_equals($expectedSignature, $signature)) {
            return false;
        }

        $decoded = json_decode($this->base64UrlDecode($encodedPayload), true);

        if (! is_array($decoded) || empty($decoded['exp'])) {
            return false;
        }

        return (int) $decoded['exp'] >= time();
    }

    private function sign(string $encodedPayload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, config('app.key'), true));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = str_pad($data, strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');

        return base64_decode(strtr($padded, '-_', '+/')) ?: '';
    }
}

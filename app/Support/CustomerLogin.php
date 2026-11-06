<?php

namespace App\Support;

use App\Models\Customer;

/**
 * Masuk pelanggan dengan email ATAU No. HP. Kode verifikasi (OTP) hanya bisa dikirim lewat email, jadi No. HP diterjemahkan ke email akun
 * pelanggan yang memiliki nomor itu: kodenya dikirim ke email tersebut. Nomor yang tidak cocok dengan akun mana pun tidak bisa dipakai
 * mendaftar (belum ada pengiriman lewat SMS atau WhatsApp); pelanggan baru mendaftar dengan email.
 */
class CustomerLogin
{
    /** Email tujuan kode verifikasi untuk isian login, atau null bila isian berupa No. HP yang tidak dikenal. */
    public static function resolveEmail(string $identifier): ?string
    {
        $identifier = trim($identifier);

        if (PhoneNumber::looksLikeEmail($identifier)) {
            return $identifier;
        }

        // Nomor pelanggan tersimpan tanpa 0 atau 62 di depan; admin dan pelanggan menulisnya 0812…, +62 812…, atau 812….
        $local = preg_replace('/^(0|62)/', '', preg_replace('/\D/', '', $identifier));

        if (strlen($local) < 8) {
            return null;
        }

        return Customer::query()
            ->whereNotNull('email')
            ->whereIn('phone_number', [$local, '0' . $local, '62' . $local])
            ->orderByRaw('email_verified_at IS NULL')
            ->value('email');
    }

    /** "juliana@gmail.com" menjadi "ju***@gmail.com": cukup untuk dikenali pemiliknya tanpa membuka alamat lengkap. */
    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, min(2, max(1, mb_strlen($name) - 1))) . '***@' . $domain;
    }
}

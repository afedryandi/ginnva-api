<?php

namespace App\Support;

/**
 * Bentuk baku No. HP untuk mencocokkan nomor yang ditulis berbeda (0812…, 62812…, +62 812…, 812…) sebagai nomor yang sama.
 * Dipakai login karyawan dengan No. HP (users.phone_digits).
 */
class PhoneNumber
{
    /** Hasilnya hanya angka berawalan 62, atau null bila bukan nomor yang masuk akal (kurang dari 9 atau lebih dari 20 angka). */
    public static function digits(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62' . $digits;
        }

        $length = strlen($digits);

        return $length >= 9 && $length <= 20 ? $digits : null;
    }

    /** Teks yang dimasukkan di kolom login: email bila mengandung "@", selain itu dianggap No. HP. */
    public static function looksLikeEmail(string $identifier): bool
    {
        return str_contains($identifier, '@');
    }
}

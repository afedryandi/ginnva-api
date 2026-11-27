<?php

namespace App\Support;

/**
 * Satu aturan baku untuk data kendaraan di Booking, SPK, dan Memo Barang (penyeragaman 2026-11-27).
 *
 * Istilah baku: Merek (Toyota), Model (Avanza), Varian (1.5 G), Tahun, Warna, dan "Jenis" khusus untuk jenis bodi SPK (sedan, SUV, MPV, jeep).
 * Kata "Tipe" tidak dipakai lagi karena dulu berarti model di satu tempat dan jenis bodi di tempat lain.
 */
class VehicleFormat
{
    /** Batas panjang yang sama di tiga modul (kolom lama yang lebih lebar tetap, tetapi isian baru dibatasi sama). */
    public const MAX_PLATE = 20;

    public const MAX_BRAND = 80;

    public const MAX_MODEL = 120;

    public const MAX_VARIANT = 120;

    public const MIN_YEAR = 1980;

    /** Jenis tenaga kendaraan (keputusan pemilik 2026-11-27): hanya tiga; diesel dan plug-in hybrid tidak dibedakan. */
    public const POWERS = ['bensin' => 'Bensin', 'listrik' => 'Listrik', 'hybrid' => 'Hybrid'];

    /** Aturan validasi jenis tenaga yang sama di semua tempat. */
    public static function powerRule(): string
    {
        return 'in:' . implode(',', array_keys(self::POWERS));
    }

    /** SPK menampilkan BBM untuk bensin dan hybrid. Jenis tenaga belum diketahui (data lama, SPK manual): tampil keduanya. */
    public static function showsFuel(?string $power): bool
    {
        return $power !== 'listrik';
    }

    /** SPK menampilkan Battery untuk listrik dan hybrid. Jenis tenaga belum diketahui: tampil keduanya. */
    public static function showsBattery(?string $power): bool
    {
        return $power !== 'bensin';
    }

    /** Plat dibakukan: huruf besar, spasi tunggal ("b 1234 xyz" menjadi "B 1234 XYZ"). Kosong menjadi null. */
    public static function normalizePlate(?string $plate): ?string
    {
        $plate = trim((string) preg_replace('/\s+/', ' ', (string) $plate));

        return $plate === '' ? null : mb_strtoupper($plate);
    }

    /** Tahun tertinggi yang diterima: tahun depan (model baru dijual lebih awal). */
    public static function maxYear(): int
    {
        return (int) now()->year + 1;
    }

    /** Aturan validasi tahun yang sama di semua modul (kolom bisa teks atau angka, isinya tetap tahun 4 digit). */
    public static function yearRule(): string
    {
        return 'integer|between:' . self::MIN_YEAR . ',' . self::maxYear();
    }

    /** Nama kendaraan untuk tampilan: "Toyota Avanza 1.5 G". Bagian kosong dilewati. */
    public static function name(?string ...$parts): string
    {
        return trim(implode(' ', array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== '')));
    }
}

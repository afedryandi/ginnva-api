<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Berkas karyawan yang sensitif (selfie absen, surat sakit/lampiran izin, scan surat peringatan) disimpan di disk PRIVAT, bukan
 * disk publik (Karyawan Fase 0, 2026-10-28; UU PDP: surat sakit adalah data kesehatan). Berkas tidak punya alamat tetap yang bisa
 * dibuka siapa pun; yang diberikan ke aplikasi dan panel hanyalah tautan bertanda tangan yang kedaluwarsa dalam beberapa menit.
 *
 * Masa transisi: berkas lama masih ada di disk publik sampai perintah `files:move-sensitive-to-private` dijalankan; url() membaca
 * disk privat dulu, lalu jatuh ke disk publik, sehingga tidak ada gambar yang tiba-tiba hilang saat deploy.
 */
class SensitiveFiles
{
    public const DISK = 'local';

    /** Simpan berkas unggahan ke disk privat; mengembalikan path relatif yang disimpan di database. */
    public static function store(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, self::DISK);
    }

    /** Hapus berkas (di kedua disk, karena berkas lama mungkin masih di disk publik). */
    public static function delete(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
        Storage::disk('public')->delete($path);
    }

    /** Tautan untuk membuka berkas: bertanda tangan dan kedaluwarsa (disk privat), atau alamat lama (berkas yang belum dipindah). */
    public static function url(?string $path, int $minutes = 30): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (Storage::disk(self::DISK)->exists($path)) {
            return Storage::disk(self::DISK)->temporaryUrl($path, now()->addMinutes($minutes));
        }

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        return null;
    }
}

<?php

namespace App\Services;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;

/**
 * Generate QR code sebagai PNG base64 data URI, untuk di-embed langsung
 * ke template Blade PDF (warranty_card.blade.php) lewat
 * <img src="data:image/png;base64,...">.
 *
 * Sengaja pakai GDLibRenderer (ekstensi PHP 'gd'), BUKAN ImagickImageBackEnd
 * — server produksi (cPanel shared hosting) tidak punya ekstensi imagick
 * terinstall, sementara 'gd' hampir selalu tersedia di lingkungan PHP
 * standar. PNG juga dipilih (bukan SVG) karena DomPDF dikenal kurang
 * stabil merender SVG yang di-embed langsung — base64 PNG adalah
 * pendekatan paling teruji untuk kombinasi QR + DomPDF.
 */
class QrCodeService
{
    /**
     * Generate QR code dari teks/data, kembalikan sebagai data URI siap
     * pakai di atribut src <img>.
     */
    public static function generateDataUri(string $data, int $size = 200): string
    {
        $renderer = new GDLibRenderer($size);
        $writer = new Writer($renderer);

        // writeString() mengembalikan binary PNG langsung (bukan file),
        // cocok untuk di-encode base64 tanpa perlu simpan file sementara
        // ke disk — menghindari isu permission/cleanup file temp di server.
        $pngBinary = $writer->writeString($data);

        return 'data:image/png;base64,' . base64_encode($pngBinary);
    }
}
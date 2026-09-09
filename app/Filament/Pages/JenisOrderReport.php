<?php

namespace App\Filament\Pages;

/**
 * "Laporan Jenis Order" — diminta 2026-09-09. SEMPAT jadi halaman
 * redirect murni ke Laporan Jasa (nama beda, data sama, tidak duplikat
 * logic), TAPI itu bikin menu sidebar "Laporan Jenis Order" TIDAK
 * PERNAH tersorot aktif — begitu diklik, browser pindah URL ke
 * layanan-report, jadi Filament menyorot menu Laporan Jasa, bukan
 * Laporan Jenis Order (URL-nya sudah beda). Diubah 2026-09-09
 * (permintaan susulan) jadi HALAMAN SENDIRI (route URL sendiri) supaya
 * menu-nya bisa tersorot.
 *
 * Tetap TIDAK duplikat logic dengan cara extends LayananReport (bukan
 * copy-paste) -- form(), getResult(), $view semuanya WARISAN dari
 * LayananReport apa adanya. Cuma properti navigasi (label/grup/sort)
 * yang di-override supaya muncul di grup 'Laporan Penjualan' dengan
 * nama "Laporan Jenis Order". Konsep "Jenis Order" di Ginnva = jenis
 * layanan (Kaca Film vs PPF) -- Ginnva cuma jual 2 jenis servis, beda
 * dari Majoo yang jenis order-nya macam-macam (dine-in/takeaway/dll).
 * Kalau LayananReport::getResult()/form() nanti berubah, halaman ini
 * OTOMATIS ikut berubah (tidak ada logic yang bisa berbeda sendiri).
 */
class JenisOrderReport extends LayananReport
{
    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Laporan Jenis Order';

    protected static ?string $title = 'Laporan Jenis Order';

    protected static ?int $navigationSort = 7;
}

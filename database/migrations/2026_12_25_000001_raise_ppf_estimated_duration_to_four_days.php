<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Perkiraan awal lama PPF jadi 4 hari (2026-11-27, keputusan pemilik): angka yang tampil ke pelanggan di aplikasi ("kurang lebih 4 hari").
 * Lama sebenarnya diatur setelah SPK. Hanya baris yang masih bernilai bawaan lama (3) yang diubah; kalau admin sudah mengubahnya sendiri
 * lewat menu Jenis Layanan, nilainya dibiarkan. Booking yang sudah ada tidak disentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('booking_service_types')->where('key', 'ppf')->where('estimated_duration_days', 3)->update(['estimated_duration_days' => 4]);
    }

    public function down(): void
    {
        DB::table('booking_service_types')->where('key', 'ppf')->where('estimated_duration_days', 4)->update(['estimated_duration_days' => 3]);
    }
};

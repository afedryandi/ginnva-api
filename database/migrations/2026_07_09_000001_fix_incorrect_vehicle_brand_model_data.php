<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Perbaikan data kendaraan yang salah pasang merek/model dari seeder awal:
 *
 * 1. VINFAST · RAIZE   -> TOYOTA · RAIZE   ("Raize" adalah mobil Toyota, bukan VinFast)
 * 2. WULING · VF3      -> VINFAST · VF3    ("VF3" adalah mobil VinFast, bukan Wuling)
 * 3. HYUNDAI · SATRIA  -> dihapus          (ternyata typo dari "STARIA" — lihat migration
 *                                            2026_07_09_000002 yang menambahkannya kembali
 *                                            dengan ejaan benar)
 * 4. ROLLS ROYCE · CONTINENTAL -> BENTLEY · CONTINENTAL ("Continental" adalah mobil Bentley)
 *
 * Pakai raw query (bukan Eloquent) supaya migration ini tidak bergantung pada
 * struktur Model yang mungkin berubah di masa depan. Dibungkus where brand+model
 * lama supaya idempotent — aman dijalankan ulang tanpa efek samping kalau data
 * sudah pernah diperbaiki manual sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vehicles')
            ->where('brand', 'VINFAST')
            ->where('model', 'RAIZE')
            ->update(['brand' => 'TOYOTA']);

        DB::table('vehicles')
            ->where('brand', 'WULING')
            ->where('model', 'VF3')
            ->update(['brand' => 'VINFAST']);

        DB::table('vehicles')
            ->where('brand', 'HYUNDAI')
            ->where('model', 'SATRIA')
            ->delete();

        DB::table('vehicles')
            ->where('brand', 'ROLLS ROYCE')
            ->where('model', 'CONTINENTAL')
            ->update(['brand' => 'BENTLEY']);
    }

    public function down(): void
    {
        DB::table('vehicles')
            ->where('brand', 'TOYOTA')
            ->where('model', 'RAIZE')
            ->update(['brand' => 'VINFAST']);

        DB::table('vehicles')
            ->where('brand', 'VINFAST')
            ->where('model', 'VF3')
            ->update(['brand' => 'WULING']);

        DB::table('vehicles')
            ->where('brand', 'BENTLEY')
            ->where('model', 'CONTINENTAL')
            ->update(['brand' => 'ROLLS ROYCE']);

        // Baris HYUNDAI · SATRIA tidak dikembalikan di sini — versi yang
        // benar (STARIA) ditambahkan lewat migration terpisah.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEBELUMNYA tidak ada — 1 akun installer bisa tertaut ke lebih dari 1
 * baris Technician tanpa ditolak, jadi data level sertifikasi ambigu
 * (User::technician() pakai hasOne, cuma ambil baris PERTAMA secara
 * arbitrer kalau ada duplikat). Unique index nullable — banyak NULL tetap
 * boleh (installer yang belum tertaut roster), cuma user_id yang SUDAH
 * TERISI yang tidak boleh dobel. Lihat audit modul Teknisi 2026-08-27.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Bersihkan duplikat dulu SEBELUM constraint ditambahkan — kalau
        // memang sudah ada 1 user_id tertaut ke >1 baris Technician (bug
        // yang mau ditutup migration ini), tambah unique index akan GAGAL
        // total tanpa langkah ini. Yang dipertahankan tertaut adalah baris
        // TERLAMA (id terkecil) per user_id, baris duplikat lainnya
        // dilepas tautannya (user_id -> NULL, baris & datanya sendiri
        // TIDAK dihapus).
        $duplicateUserIds = \Illuminate\Support\Facades\DB::table('technicians')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        foreach ($duplicateUserIds as $userId) {
            $keepId = \Illuminate\Support\Facades\DB::table('technicians')
                ->where('user_id', $userId)
                ->orderBy('id')
                ->value('id');

            \Illuminate\Support\Facades\DB::table('technicians')
                ->where('user_id', $userId)
                ->where('id', '!=', $keepId)
                ->update(['user_id' => null]);
        }

        Schema::table('technicians', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });
    }
};

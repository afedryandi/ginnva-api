<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status aktif/nonaktif bahan baku (audit Daftar Bahan Baku, 2026-11-02). Bahan yang sudah punya riwayat tidak boleh dihapus, jadi
 * yang tidak dipakai lagi dinonaktifkan: hilang dari pemilih Memo Bahan/Permohonan Pembelian dan dari peringatan (menipis, kedaluwarsa,
 * tidak bergerak), tetapi stok, riwayat, dan laporannya tetap utuh. Semua bahan yang ada tetap aktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('notes')->index();
        });
    }

    public function down(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};

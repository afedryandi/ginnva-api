<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koreksi panjang gulungan oleh admin (audit Produk PPF/WF, 2026-11-02): "Edit Panjang" mengubah sisa panjang langsung tanpa baris riwayat
 * pemakaian, sehingga "Cek Rekonsiliasi" (sisa = total - riwayat pemakaian) selalu menandainya selisih. Selisih bersih akibat koreksi manual
 * disimpan di sini dan ikut dihitung rekonsiliasi; riwayat pemakaian dan laporan meter terpakai tidak tercemar baris koreksi. Gulungan
 * yang sudah pernah dikoreksi sebelum kolom ini ada tetap tampil selisih sampai dikoreksi ulang lewat aksi yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scroll_codes', function (Blueprint $table) {
            $table->decimal('length_adjustment_meters', 8, 2)->default(0)->after('remaining_length_meters');
        });
    }

    public function down(): void
    {
        Schema::table('scroll_codes', function (Blueprint $table) {
            $table->dropColumn('length_adjustment_meters');
        });
    }
};

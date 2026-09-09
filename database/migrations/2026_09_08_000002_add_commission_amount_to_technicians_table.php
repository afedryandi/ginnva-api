<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diminta 2026-09-08 -- analog "Komisi per Kasir" Majoo, versi Ginnva
 * jadi "Komisi per Teknisi" (per pekerjaan/mobil, bukan per transaksi
 * kasir). Aturan yang dikonfirmasi user:
 * - NOMINAL TETAP per pekerjaan (bukan persentase dari transaction_amount)
 * - Kalau 1 booking ditugaskan >1 teknisi sekaligus, MASING-MASING dapat
 *   komisi PENUH (bukan dibagi rata)
 *
 * commission_amount disimpan PER TEKNISI (bukan 1 rate global) supaya
 * fleksibel kalau ke depannya beda teknisi beda nominal, TAPI defaultnya
 * NULL (belum diisi) -- laporan komisi HARUS memperlakukan NULL sebagai
 * "belum diatur", bukan Rp 0, supaya tidak menyesatkan (booking teknisi
 * yang belum diatur nominalnya jangan sampai terhitung komisi 0 padahal
 * sebenarnya cuma belum di-setting).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->decimal('commission_amount', 12, 2)->nullable()->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropColumn('commission_amount');
        });
    }
};

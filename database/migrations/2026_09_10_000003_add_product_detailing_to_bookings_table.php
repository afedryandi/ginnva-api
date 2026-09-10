<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "booking ini termasuk jasa detailing" — sejajar dengan
 * boolean product_kaca_film / product_ppf yang sudah ada. Diminta
 * 2026-09-10 (detailing dijual dua-duanya: sendiri atau tambahan).
 *
 * SENGAJA tidak diikutkan ke mesin tahap/progress booking
 * (Booking::stageColumnFor() dkk) maupun ke opsi service_type / mobile
 * app — detailing tidak punya alur instalasi bertahap seperti film.
 * Fungsinya sekarang: (a) auto-isi Memo Barang dari Master Resep
 * detailing, (b) nanti penanda untuk laporan penjualan kalau harga
 * detailing sudah ditetapkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('product_detailing')->default(false)->after('product_ppf');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('product_detailing');
        });
    }
};

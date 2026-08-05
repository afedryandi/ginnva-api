<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * scroll_codes.film_product_id sebelumnya nullOnDelete() — kalau
 * FilmProduct yang masih punya ScrollCode aktif dihapus, ScrollCode-nya
 * TIDAK ikut terhapus, cuma kehilangan referensi produk secara diam-diam
 * (jadi yatim, otomatis hilang dari semua dropdown pemilihan garansi
 * karena query WarrantyResource pakai whereHas('filmProduct', ...)).
 * Diganti jadi restrictOnDelete(), konsisten dengan quotation_items.
 * film_product_id yang sudah lebih dulu pakai restrict — sekarang hapus
 * FilmProduct yang masih dipakai ScrollCode akan DITOLAK database dengan
 * pesan jelas, bukan diam-diam merusak data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scroll_codes', function (Blueprint $table) {
            $table->dropForeign(['film_product_id']);
        });

        Schema::table('scroll_codes', function (Blueprint $table) {
            $table->foreign('film_product_id')
                  ->references('id')->on('film_products')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scroll_codes', function (Blueprint $table) {
            $table->dropForeign(['film_product_id']);
        });

        Schema::table('scroll_codes', function (Blueprint $table) {
            $table->foreign('film_product_id')
                  ->references('id')->on('film_products')
                  ->nullOnDelete();
        });
    }
};

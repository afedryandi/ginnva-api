<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ginnva ternyata JUAL jasa detailing (dikonfirmasi user 2026-09-10 —
 * sebelumnya di Majoo cuma kelihatan 1 item kategori "Detailing").
 * Aturan yang dikonfirmasi: dijual DUA-DUANYA (berdiri sendiri ATAU
 * tambahan pada booking film), pakai bahan yang perlu Master Resep,
 * cukup SATU layanan umum (tidak dipecah varian).
 *
 * Ditampung di FilmProduct (bukan model baru) — semua downstream
 * (film_product_id di booking, Master Resep, laporan Produk) sudah
 * nempel ke FilmProduct/product_type, jadi nambah 1 nilai enum + 1 baris
 * produk jauh lebih murah daripada bikin model layanan terpisah yang
 * menduplikasi semua itu. Kolom `position` tetap NOT NULL (isi 'front',
 * tidak dipakai untuk detailing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('film_products', function (Blueprint $table) {
            $table->enum('product_type', ['window_film', 'ppf', 'color_change', 'detailing'])->change();
        });

        if (! DB::table('film_products')->where('product_type', 'detailing')->exists()) {
            DB::table('film_products')->insert([
                'sku' => 'SVC-DETAILING',
                'name' => 'Detailing',
                'product_type' => 'detailing',
                'position' => 'front',
                'base_price' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('film_products')
            ->where('product_type', 'detailing')
            ->where('sku', 'SVC-DETAILING')
            ->delete();

        Schema::table('film_products', function (Blueprint $table) {
            $table->enum('product_type', ['window_film', 'ppf', 'color_change'])->change();
        });
    }
};

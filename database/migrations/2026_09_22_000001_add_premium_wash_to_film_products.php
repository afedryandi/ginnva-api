<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ginnva ternyata JUAL jasa Premium Wash (cuci premium), dikonfirmasi
 * user 2026-09-22 saat menjawab pertanyaan pending "Produk Layanan"
 * dari audit Majoo (2026-09-10) — "kita ada jual jasa detailing dan
 * premium wash". SATU dari 2 layanan konkret yang dikonfirmasi ada
 * (Detailing sudah dibangun 2026-09-10, migrasi 2026_09_10_000002).
 *
 * Pola SAMA PERSIS dengan Detailing — ditampung di FilmProduct (bukan
 * model baru), 1 nilai enum `product_type` baru + 1 baris produk
 * otomatis, supaya semua downstream (film_product_id di booking,
 * Master Resep, laporan Produk) tetap nempel ke infrastruktur yang
 * sudah ada. Kolom `position` tetap diisi 'front' (tidak dipakai untuk
 * layanan non-film, sama seperti Detailing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('film_products', function (Blueprint $table) {
            $table->enum('product_type', ['window_film', 'ppf', 'color_change', 'detailing', 'premium_wash'])->change();
        });

        if (! DB::table('film_products')->where('product_type', 'premium_wash')->exists()) {
            DB::table('film_products')->insert([
                'sku' => 'SVC-PREMIUM-WASH',
                'name' => 'Premium Wash',
                'product_type' => 'premium_wash',
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
            ->where('product_type', 'premium_wash')
            ->where('sku', 'SVC-PREMIUM-WASH')
            ->delete();

        Schema::table('film_products', function (Blueprint $table) {
            $table->enum('product_type', ['window_film', 'ppf', 'color_change', 'detailing'])->change();
        });
    }
};

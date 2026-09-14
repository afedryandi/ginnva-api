<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Sisa Roll" — diminta 2026-09-14. Kumpulan sisa panjang + potongan
 * lebar (masih bisa dipakai) dari berbagai kode gulungan (ScrollCode)
 * yang sudah dipakai instalasi, dikumpulkan jadi 1 pool per (toko,
 * produk film) supaya bisa dijual/dipakai lagi tanpa membuka roll
 * baru. SEBELUMNYA sisa ini tidak tercatat sama sekali — staff bisa
 * "diam-diam" pakai sisaan buat instalasi walau stok roll tercatat
 * sudah habis (unaccounted usage).
 *
 * Per-toko (bukan nasional) — konsisten dengan ScrollCode yang sudah
 * per-toko. Dikelompokkan per FilmProduct supaya tetap tahu varian
 * apa yang dipakai saat sisa ini dipakai lagi nanti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roll_scrap_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('film_product_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('remaining_length_meters', 6, 2)->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'film_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roll_scrap_pools');
    }
};

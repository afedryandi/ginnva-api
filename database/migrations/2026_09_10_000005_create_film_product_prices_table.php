<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matriks harga jual per (produk film × ukuran kendaraan) — diminta
 * 2026-09-10 setelah ekspor daftar harga Majoo membuktikan model
 * `base_price × koefisien` TIDAK bisa mereproduksi harga riil (rasio
 * antar-ukuran beda per lini produk; lihat docs/majoo-price-list-2026-09-10.md).
 *
 * Model harga final: `FilmProduct.base_price` = harga flat/dasar
 * (dipakai produk yang harganya sama semua ukuran, mis. Panoramic);
 * baris di tabel INI = override spesifik per ukuran (Platinum/Signature/
 * PPF). PriceCalculator: cari baris ukuran → kalau tidak ada, pakai
 * base_price.
 *
 * vehicle_size nullable secara skema tapi praktiknya selalu diisi salah
 * satu S/M/L/XL/XXL (harga flat = base_price, bukan baris di sini).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('film_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('film_product_id')->constrained('film_products')->cascadeOnDelete();
            $table->enum('vehicle_size', ['S', 'M', 'L', 'XL', 'XXL'])->nullable();
            $table->decimal('price', 15, 2);
            $table->timestamps();

            $table->unique(['film_product_id', 'vehicle_size']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('film_product_prices');
    }
};

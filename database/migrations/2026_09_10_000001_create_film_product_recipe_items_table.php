<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Master Resep" (BOM) per Produk Film — diminta 2026-09-10, analog
 * menu Majoo "Master Resep". Isinya: bahan standar yang dipakai untuk
 * 1x pemasangan produk film tertentu (mis. "1 pasang Kaca Depan A70 =
 * 3.5 meter roll + 1 pcs karet wiper + 20 ml cairan").
 *
 * SENGAJA tanpa kolom harga/biaya — keputusan HPP Ginnva masih
 * "belum tau pasti nya" (lihat memory project_penjualan_majoo_blocked_items).
 * Yang dicatat cuma KUANTITAS (pengetahuan operasional yang staf sudah
 * punya sekarang). Layer HPP tinggal kali `standard_qty` x `unit_cost`
 * bahannya kalau keputusan itu sudah turun.
 *
 * 1 baris = 1 bahan dalam resep. Resep sebuah produk = semua baris
 * dengan film_product_id sama (tidak ada tabel "resep" induk terpisah —
 * relasi 1 produk : 1 resep implisit, tidak perlu induk kosong).
 *
 * Struktur item_type/item_id MENIRU material_memo_items supaya auto-isi
 * Memo Barang nanti lurus. 'film_roll' = konsumsi meteran roll film
 * produk itu sendiri (item_id null — roll diidentifikasi lewat
 * film_product_id + ScrollCode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('film_product_recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('film_product_id')->constrained('film_products')->cascadeOnDelete();
            $table->enum('item_type', ['raw_material', 'consumable_item', 'film_roll']);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->string('unit')->nullable();
            $table->decimal('standard_qty', 10, 2)->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['film_product_id', 'item_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('film_product_recipe_items');
    }
};

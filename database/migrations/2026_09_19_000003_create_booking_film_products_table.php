<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian produk TAMBAHAN per bagian kendaraan -- keputusan atasan
 * 2026-09-19 (Topik 3, "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"): 1
 * booking BISA pakai lebih dari 1 produk sekaligus (mis. kaca depan
 * pakai varian berbeda dari kaca samping/belakang), dicatat STAFF TOKO
 * saat booking dikonfirmasi.
 *
 * SENGAJA ADDITIF, TIDAK mengganti `bookings.film_product_id` yang
 * sudah ada -- kolom itu tetap berarti "produk UTAMA" booking ini
 * (dipakai APA ADANYA oleh 15+ file yang sudah bergantung padanya:
 * Master Resep auto-fill Memo Barang, Laporan Produk Terlaris, Invoice,
 * Warranty, Serial Number Report, dst -- lihat catatan lengkap di
 * project_penjualan_majoo_blocked_items). Tabel ini cuma menampung
 * produk KEDUA/KETIGA/dst untuk bagian kendaraan lain, supaya booking
 * yang genuinely butuh multi-produk tidak dipaksa pilih 1 SKU saja.
 *
 * `position` SENGAJA string bebas (bukan enum tetap) -- Kaca Film pakai
 * istilah "Kaca Depan/Samping/Belakang", PPF pakai istilah lain
 * ("Bumper Depan/Full Body/dst"), enum tetap akan membatasi salah satu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_film_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('film_product_id')->constrained()->restrictOnDelete();
            $table->string('position')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_film_products');
    }
};

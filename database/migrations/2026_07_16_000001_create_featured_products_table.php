<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section "Seri Produk" di beranda mobile app — kartu produk unggulan yang
 * dikurasi manual oleh admin lewat Filament (bukan otomatis dari data
 * penjualan/booking). Strukturnya sengaja disamakan dengan Carousel
 * (gambar + judul + sub-judul + link + urutan + aktif) karena kebutuhannya
 * mirip: admin upload 1 gambar per kartu, sisanya cuma metadata tampilan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('featured_products', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('image');
            $table->string('link_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('featured_products');
    }
};

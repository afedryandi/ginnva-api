<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Galeri pemasangan ("Case Show") di home page — sebelumnya hardcoded
     * di CaseAndNewsSection.tsx (CASE_DATA array). Tabel ini menggantikan
     * itu, dengan relasi ke Vehicle & FilmProduct yang sudah ada supaya
     * data mobil/produk konsisten dengan yang dipakai di quotation,
     * bukan teks bebas yang bisa typo/tidak sinkron.
     */
    public function up(): void
    {
        Schema::create('case_studies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vehicle_id')
                ->constrained('vehicles')
                ->cascadeOnDelete();

            $table->foreignId('film_product_id')
                ->constrained('film_products')
                ->cascadeOnDelete();

            // Judul tetap disimpan sebagai teks (bukan auto-generate dari
            // relasi) supaya admin bisa kustomisasi gaya penulisan, mis.
            // "Kaca Film · Zeekr 9X — Ginnva Ziwei 70" — title lengkap
            // untuk bigPic, short_title untuk label thumbnail.
            $table->string('title');
            $table->string('short_title');

            $table->string('image'); // path relatif, disimpan oleh Filament FileUpload

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_studies');
    }
};
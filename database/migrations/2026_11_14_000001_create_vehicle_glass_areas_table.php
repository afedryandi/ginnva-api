<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Data Fase 2 (2026-10-21): luas kaca (m²) per merek+model di database. Sebelumnya hanya ada di Google Sheet "Data Ukuran" yang
 * dibaca halaman daftar harga. Dikunci pada merek+model (bukan varian) seperti sheet-nya; kunci ternormalisasi (huruf kecil, spasi rapi)
 * supaya "Honda  Brio" dan "HONDA BRIO" tidak menjadi dua baris. Tabel awalnya kosong; diisi lewat impor dengan pratinjau.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicle_glass_areas')) {
            return;
        }

        Schema::create('vehicle_glass_areas', function (Blueprint $table) {
            $table->id();
            $table->string('brand', 80);
            $table->string('model', 120);
            $table->string('brand_key', 80);
            $table->string('model_key', 120);
            $table->decimal('front_sqm', 8, 3)->default(0);
            $table->decimal('side_sqm', 8, 3)->default(0);
            $table->decimal('rear_sqm', 8, 3)->default(0);
            $table->decimal('sunroof_sqm', 8, 3)->default(0);
            // import | manual
            $table->string('source', 10)->default('manual');
            $table->timestamp('imported_at')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['brand_key', 'model_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_glass_areas');
    }
};

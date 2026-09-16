<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris centang SPK (Uraian Pekerjaan, Extra Services, Perlengkapan
 * Kendaraan) -- tabel TERPISAH (bukan kolom boolean satu-satu di
 * `spks`) karena form kertas aslinya punya baris kosong isian bebas
 * ("......................"), jumlah item per kategori tidak tetap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spk_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spk_id')->constrained()->cascadeOnDelete();
            $table->enum('category', ['pekerjaan', 'extra_service', 'perlengkapan']);
            $table->string('label');
            $table->boolean('is_checked')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['spk_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spk_checklist_items');
    }
};

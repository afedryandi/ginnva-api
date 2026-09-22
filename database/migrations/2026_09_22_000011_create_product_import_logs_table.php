<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Riwayat Impor" (audit Majoo, f27: "Impor/Ekspor bulk data produk +
 * riwayat impor"), dibangun 2026-09-22. 1 baris = 1 kali proses import
 * Excel/CSV lewat FilmProductResource ("Import Harga Massal") —
 * append-only, tidak pernah diedit/dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename');
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('updated_count');
            $table->unsignedInteger('skipped_count');
            $table->json('errors')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_import_logs');
    }
};

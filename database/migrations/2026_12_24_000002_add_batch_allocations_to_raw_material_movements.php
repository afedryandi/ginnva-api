<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stok keluar mencatat batch mana yang terpakai (FIFO) dan berapa, supaya bahan yang dikembalikan lewat memo masuk lagi ke batch asalnya,
 * bukan membuka Batch Penerimaan baru. Pergerakan lama tidak punya catatan ini (kolom kosong) dan memakai jalur cadangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->json('batch_allocations')->nullable()->after('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->dropColumn('batch_allocations');
        });
    }
};

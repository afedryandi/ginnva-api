<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permohonan Pembelian jenis "Lainnya" (2026-11-17): pembelian barang di luar stok inventori (nama dan satuan diketik bebas). Kolom item_type
 * tadinya ENUM (raw_material, consumable_item, asset); dilonggarkan jadi string supaya jenis baru tidak perlu mengubah skema lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('item_type', 30)->change();
        });
    }

    public function down(): void
    {
        // Tidak dikembalikan ke ENUM: baris jenis "other" yang sudah ada akan membuatnya gagal.
    }
};

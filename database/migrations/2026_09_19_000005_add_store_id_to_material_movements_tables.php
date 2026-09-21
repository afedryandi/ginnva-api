<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Penanda cabang pada pencatatan pemakaian bahan" -- keputusan atasan
 * 2026-09-19 (Topik 4, Fase 1, "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx").
 *
 * SENGAJA cuma menambah PENANDA (kolom nullable, opsional), BUKAN
 * memecah `current_stock` jadi per-cabang -- itu Fase 2, ditunda sampai
 * jelas dibutuhkan (lihat "BLOCKER FUNDAMENTAL" di
 * project_penjualan_majoo_blocked_items). Stok bahan baku/consumable
 * TETAP 1 angka nasional; kolom ini cuma mencatat CABANG MANA yang
 * melakukan 1 kejadian pemakaian/penyesuaian tertentu, supaya laporan
 * "pemakaian bahan per cabang" bisa dibuat nanti tanpa join rapuh
 * berbasis waktu ke MaterialMemo.
 *
 * Nullable & tanpa backfill -- movement lama tetap valid apa adanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('raw_material_id')
                ->constrained('stores')->nullOnDelete();
        });

        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('consumable_item_id')
                ->constrained('stores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stok Opname gulungan PPF/WF (2026-11-17): item_type tadinya enum(raw_material, consumable_item) sehingga 'scroll_code' ditolak.
 * Diubah jadi string supaya jenis baru tidak perlu migrasi lagi. Data lama tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->string('item_type', 30)->change();
        });
    }

    /** Dikembalikan ke enum lama; baris gulungan dihapus dulu karena nilainya tidak muat di enum. */
    public function down(): void
    {
        DB::table('stock_opname_items')->where('item_type', 'scroll_code')->delete();

        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->enum('item_type', ['raw_material', 'consumable_item'])->change();
        });
    }
};

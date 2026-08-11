<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opsional (nullable) — InventoryItem dipakai untuk SEMUA jenis barang
     * gudang, bukan cuma PPF/window film, jadi tidak semua baris punya
     * kode gulungan. 1 kode gulungan cuma boleh dipasangkan ke 1 unit
     * fisik (unique) supaya tidak ada 2 kardus yang diklaim sebagai
     * gulungan yang sama.
     */
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->foreignId('scroll_code_id')->nullable()->after('category')
                ->constrained('scroll_codes')->nullOnDelete();
            $table->unique('scroll_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scroll_code_id');
        });
    }
};

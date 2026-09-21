<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris per-item dalam 1 sesi Stok Opname -- pola manual-morph SAMA
 * PERSIS dengan `stock_write_offs` (enum item_type + unsignedBigInteger
 * item_id, tanpa FK karena menunjuk ke 2 tabel berbeda).
 *
 * `system_quantity` = snapshot stok sistem SAAT opname dibuat (sebelum
 * disesuaikan) -- disimpan eksplisit (bukan dihitung ulang belakangan)
 * supaya baris ini tetap akurat sbg jejak historis meski stok berubah
 * lagi setelahnya. `actual_quantity` = hasil hitung fisik staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained()->cascadeOnDelete();
            $table->enum('item_type', ['raw_material', 'consumable_item']);
            $table->unsignedBigInteger('item_id');
            $table->string('item_name');
            $table->string('unit')->nullable();
            $table->decimal('system_quantity', 14, 2);
            $table->decimal('actual_quantity', 14, 2);
            $table->decimal('delta', 14, 2);
            $table->timestamps();

            $table->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
    }
};

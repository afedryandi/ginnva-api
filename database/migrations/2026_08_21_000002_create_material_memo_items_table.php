<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_memo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_memo_id')->constrained()->cascadeOnDelete();
            // item_type: 'raw_material' | 'consumable_item' | 'inventory_item'
            // (inventory_item = barang PPF/WF yang punya kode gulungan
            // terkait — bukan relasi Eloquent polymorphic beneran, cukup
            // string+id manual & di-resolve di controller, sama gaya
            // dengan sisa codebase ini yang belum pernah pakai morphTo()).
            $table->string('item_type');
            $table->unsignedBigInteger('item_id');
            // Snapshot nama & satuan saat baris dibuat — supaya histori
            // memo tetap kebaca meski nama/satuan barang diubah belakangan.
            $table->string('item_name');
            $table->string('unit')->nullable();
            // Bahan Baku / Barang Habis Pakai: diambil -> dikembalikan ->
            // terpakai (otomatis). Null semua untuk item_type=inventory_item.
            $table->decimal('qty_taken', 12, 2)->nullable();
            $table->decimal('qty_returned', 12, 2)->nullable();
            $table->decimal('qty_used', 12, 2)->nullable();
            // PPF/WF: meter dipakai dari gulungan (recordUsage langsung,
            // tidak ada konsep "dikembalikan"). Null untuk 2 jenis lainnya.
            $table->decimal('meters_used', 10, 2)->nullable();
            $table->text('condition_notes')->nullable();
            $table->timestamps();

            $table->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_memo_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sama persis bug yang sudah diperbaiki di inventory_movements dan
 * raw_material_movements — lihat migration 2026_08_22_000002. consumable_item_movements
 * adalah audit trail masuk/keluar/opname barang habis pakai — SEBELUMNYA
 * cascadeOnDelete() ke consumable_items, jadi begitu 1 barang dihapus,
 * seluruh riwayatnya ikut lenyap permanen. Kode Filament
 * (ConsumableItemMovementResource) bahkan sudah punya placeholder "—
 * (barang sudah dihapus)" seolah-olah mengasumsikan ini nullOnDelete() —
 * tapi migrasinya belum pernah diubah sampai sekarang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->dropForeign(['consumable_item_id']);
        });

        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->foreignId('consumable_item_id')->nullable()->change();
        });

        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->foreign('consumable_item_id')->references('id')->on('consumable_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->dropForeign(['consumable_item_id']);
        });

        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->foreignId('consumable_item_id')->nullable(false)->change();
        });

        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->foreign('consumable_item_id')->references('id')->on('consumable_items')->cascadeOnDelete();
        });
    }
};

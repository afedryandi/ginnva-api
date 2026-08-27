<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inventory_movements adalah audit trail keluar/masuk barang — SEBELUMNYA
 * cascadeOnDelete() ke inventory_items, jadi begitu 1 InventoryItem
 * dihapus (full-access boleh via DeleteBulkAction), seluruh riwayat
 * keluar/masuknya ikut lenyap. Ini berlawanan dengan tujuan modul ini
 * sebagai audit trail — dan kode di InventoryMovementResource/Export
 * SUDAH siap menangani baris movement yang produknya sudah terhapus
 * (placeholder "— (produk sudah dihapus)"), tapi kondisi itu tidak
 * pernah tercapai karena barisnya keburu ikut terhapus duluan.
 *
 * Diganti nullOnDelete() — produk boleh dihapus, riwayatnya tetap ada
 * (inventory_item_id jadi NULL, ditampilkan sebagai "produk sudah
 * dihapus").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['inventory_item_id']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('inventory_item_id')->nullable()->change();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreign('inventory_item_id')->references('id')->on('inventory_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['inventory_item_id']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('inventory_item_id')->nullable(false)->change();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreign('inventory_item_id')->references('id')->on('inventory_items')->cascadeOnDelete();
        });
    }
};

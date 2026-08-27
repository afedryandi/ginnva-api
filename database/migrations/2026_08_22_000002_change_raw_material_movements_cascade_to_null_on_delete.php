<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sama persis bug yang sudah diperbaiki di inventory_movements (PPF/WF) —
 * lihat migration 2026_08_21_000004_change_inventory_movements_cascade_to_null_on_delete.
 * raw_material_movements adalah audit trail masuk/keluar/opname bahan
 * baku — SEBELUMNYA cascadeOnDelete() ke raw_materials, jadi begitu 1
 * bahan dihapus, seluruh riwayatnya ikut lenyap permanen. Kode Filament
 * (RawMaterialMovementResource) bahkan SUDAH punya placeholder "— (bahan
 * sudah dihapus)" seolah-olah mengasumsikan ini nullOnDelete() — tapi
 * migrasinya belum pernah diubah sampai sekarang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->dropForeign(['raw_material_id']);
        });

        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->foreignId('raw_material_id')->nullable()->change();
        });

        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->foreign('raw_material_id')->references('id')->on('raw_materials')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->dropForeign(['raw_material_id']);
        });

        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->foreignId('raw_material_id')->nullable(false)->change();
        });

        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->foreign('raw_material_id')->references('id')->on('raw_materials')->cascadeOnDelete();
        });
    }
};

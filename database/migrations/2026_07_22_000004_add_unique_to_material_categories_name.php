<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * material_categories.name sebelumnya tidak punya unique constraint sama
 * sekali — kategori cuma bisa dibuat inline lewat form Material, tanpa
 * cara edit/hapus/cegah duplikat. Sekarang ada MaterialCategoryResource
 * + validasi Filament unique(), ditambah constraint di level database
 * ini sebagai pengaman terakhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_categories', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('material_categories', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }
};

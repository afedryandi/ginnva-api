<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kode barang internal perusahaan (BUKAN auto-generate seperti kode
     * QR di InventoryItem/Asset) — bahan baku sudah punya sistem
     * penomoran kode sendiri, staff tinggal input manual pas daftarkan.
     * Nullable+unique (bukan wajib) supaya data lama yang belum sempat
     * diisi kodenya tidak error, tapi begitu diisi tidak boleh dobel.
     */
    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};

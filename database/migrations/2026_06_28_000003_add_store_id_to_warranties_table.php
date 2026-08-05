<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan store_id ke warranties supaya data garansi bisa
     * dikaitkan ke toko/dealer tertentu (dipakai untuk scoping
     * store_manager di Filament).
     *
     * Nullable & tidak menggantikan kolom `dealer_name` (tetap dipakai
     * untuk tampilan publik / data lama yang belum dikaitkan ke store).
     */
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->foreignId('store_id')
                ->nullable()
                ->after('dealer_name')
                ->constrained('stores')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};

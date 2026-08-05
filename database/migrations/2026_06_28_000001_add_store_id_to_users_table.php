<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan store_id ke tabel users.
     *
     * Dipakai untuk men-scope role "store_manager" (= admin toko/dealer)
     * ke SATU store tertentu. super_admin tidak butuh kolom ini diisi
     * (akses ke semua data, lihat AdminPanelProvider & Policy).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('store_id')
                ->nullable()
                ->after('id')
                ->constrained('stores')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};

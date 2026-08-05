<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perbaikan insiden — kolom install_capacity_per_day sempat terhapus
     * di beberapa environment karena migration drop yang tidak jadi
     * dipakai (dibuat lalu dibatalkan sebelum sempat di-push, tapi
     * terlanjur dijalankan manual di sebagian server). Idempotent supaya
     * aman dijalankan di server mana pun kondisinya (kolom masih ada
     * atau sudah kehapus).
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'install_capacity_per_day')) {
                $table->unsignedTinyInteger('install_capacity_per_day')->default(3)->after('opening_hours');
            }
        });
    }

    public function down(): void
    {
        // Sengaja tidak drop lagi — ini justru migration yang
        // memperbaiki insiden drop yang tidak disengaja sebelumnya.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kapan staf menyelesaikan/melewati tur pengenalan panel admin (2026-10-12). Null = belum pernah, jadi tur tampil sekali
 * saat login berikutnya. Disimpan di database (bukan localStorage) supaya tidak muncul lagi di perangkat atau browser lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('admin_tour_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_tour_seen_at');
        });
    }
};

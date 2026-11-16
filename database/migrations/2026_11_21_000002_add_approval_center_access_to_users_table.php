<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hak atas halaman Pusat Persetujuan per pengguna: none (tidak bisa membuka), view (melihat saja), decide (boleh memutuskan). Null = ikut
 * peran (store manager decide, SPV Finance view, lainnya tidak ada). Akses penuh selalu bisa memutuskan, apa pun isi kolom ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('approval_center_access', 10)->nullable()->after('menu_permissions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('approval_center_access');
        });
    }
};

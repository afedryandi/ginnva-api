<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Daftar menu (nama class Resource, mis. "BookingResource") yang
            // boleh diakses user ini — khusus role staff/divisi (super_admin
            // & direksi selalu full akses, tidak dibatasi field ini sama
            // sekali).
            //
            // NULL = belum diatur = akses penuh ke semua menu yang biasanya
            // boleh dilihat staff — SENGAJA begini (bukan array kosong)
            // supaya akun staff yang sudah ada sebelum fitur ini tidak
            // mendadak ke-lock dari menu yang biasa mereka pakai. Array
            // kosong `[]` baru berarti "tidak ada akses sama sekali" — beda
            // dari NULL.
            $table->json('menu_access')->nullable()->after('store_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('menu_access');
        });
    }
};

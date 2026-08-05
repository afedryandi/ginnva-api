<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "akun ini pernah minta dihapus" — bukan Laravel SoftDeletes
 * bawaan (row TETAP ada, cuma PII-nya dikosongkan/dianonimkan), supaya
 * histori bisnis yang terikat customer_id (booking, warranty, transaksi
 * poin) tetap utuh untuk kebutuhan operasional/legal toko, sesuai
 * kebijakan Google Play soal account deletion — lihat
 * CustomerAuthController::deleteAccount().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('loyalty_points');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }
};

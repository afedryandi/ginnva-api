<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat Keluar/Masuk (audit 2026-11-03): toko pelaku pada setiap pergerakan (Masuk tidak punya toko sama sekali sebelumnya), dan
 * pembatalan yang menyimpan baris aslinya (sebelumnya dihapus, sehingga siapa/kapan/ke mana yang salah ikut hilang).
 * Baris lama diisi toko dari akun pencatatnya SAAT INI -- perkiraan, bisa keliru bila staf pernah pindah toko.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('destination_store_id')->constrained('stores')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('user_id');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->string('reversed_reason', 500)->nullable()->after('reversed_by');
            $table->index(['store_id', 'created_at']);
        });

        DB::statement('UPDATE inventory_movements SET store_id = (SELECT store_id FROM users WHERE users.id = inventory_movements.user_id) WHERE user_id IS NOT NULL AND store_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'created_at']);
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropConstrainedForeignId('store_id');
            $table->dropColumn(['reversed_at', 'reversed_reason']);
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat Barang Habis Pakai (audit 2026-11-03): pembatalan menyimpan baris aslinya dengan alasan wajib (sebelumnya dihapus),
 * sama dengan Riwayat Bahan Baku. Penanda sumber (source_type/source_id) sudah ada dari migrasi 2026_11_30_000009.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('user_id');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->string('reversed_reason', 500)->nullable()->after('reversed_by');
            $table->index(['store_id', 'created_at'], 'cim_store_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->dropIndex('cim_store_created_idx');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversed_reason']);
        });
    }
};
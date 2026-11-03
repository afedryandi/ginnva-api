<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stok Opname alur dua tahap (audit 2026-11-03): sesi berstatus draft (hasil hitung tersimpan, stok belum berubah), applied
 * (diterapkan akses penuh), atau cancelled (draf dibuang). Sesi lama sudah menyesuaikan stok saat dibuat, jadi dimigrasikan sebagai
 * applied. Baris sesi mendapat catatan per item dan stok sistem saat diterapkan (untuk melihat stok yang berubah sejak dihitung).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->string('status', 20)->default('applied')->after('notes')->index();
            $table->timestamp('snapshot_at')->nullable()->after('status');
            $table->timestamp('applied_at')->nullable()->after('snapshot_at');
            $table->foreignId('applied_by')->nullable()->after('applied_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('applied_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable()->after('cancelled_by');
        });

        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->string('note', 255)->nullable()->after('delta');
            $table->decimal('applied_system_quantity', 12, 2)->nullable()->after('note');
        });

        DB::statement('UPDATE stock_opnames SET applied_at = created_at, applied_by = created_by, snapshot_at = created_at');
    }

    public function down(): void
    {
        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->dropColumn(['note', 'applied_system_quantity']);
        });

        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('applied_by');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'snapshot_at', 'applied_at', 'cancelled_at', 'cancel_reason']);
        });
    }
};
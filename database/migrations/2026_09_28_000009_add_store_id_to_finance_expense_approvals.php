<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Persetujuan Pengeluaran 2026-09-28: scoping store manager membaca
 * store_id dari kolom JSON payload (JSON_EXTRACT tanpa index, khusus MySQL).
 * Diganti kolom store_id biasa + index + FK, di-backfill dari payload.
 * Payload tetap menyimpan store_id (dipakai saat transaksi dibuat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_expense_approvals', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('requested_by')
                ->constrained('stores')->nullOnDelete();
            $table->index(['store_id', 'status']);
        });

        DB::statement("
            UPDATE finance_expense_approvals
            SET store_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.store_id')) AS UNSIGNED)
            WHERE store_id IS NULL
              AND JSON_EXTRACT(payload, '\$.store_id') IS NOT NULL
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.store_id')) AS UNSIGNED) IN (SELECT id FROM stores)
        ");
    }

    public function down(): void
    {
        Schema::table('finance_expense_approvals', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'status']);
            $table->dropConstrainedForeignId('store_id');
        });
    }
};

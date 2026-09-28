<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Hutang Usaha 2026-09-29:
 * - payables.status: ENUM(unpaid,partial,paid) -> VARCHAR(20) supaya bisa 'cancelled'
 *   (VARCHAR = superset, semua data lama tetap valid; sama pola migrasi 000001).
 * - payables: kolom pembatalan tagihan (siapa, kapan, alasan, jurnal pembaliknya).
 * - payable_payments: kolom pembatalan pembayaran (void) -- baris TIDAK dihapus
 *   supaya jejak audit utuh; pembayaran aktif = voided_at IS NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payables MODIFY status VARCHAR(20) NOT NULL DEFAULT 'unpaid'");
        }

        Schema::table('payables', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('created_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable()->after('cancelled_by');
            $table->foreignId('cancel_journal_entry_id')->nullable()->after('cancel_reason')->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('payable_payments', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('created_by');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by');
            $table->foreignId('void_journal_entry_id')->nullable()->after('void_reason')->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payable_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('void_journal_entry_id');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });

        Schema::table('payables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancel_journal_entry_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });

        // Kolom status sengaja TIDAK dikembalikan ke ENUM (VARCHAR superset, aman).
    }
};

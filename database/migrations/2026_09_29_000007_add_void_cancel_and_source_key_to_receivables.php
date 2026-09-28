<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Piutang Usaha 2026-09-29 (bug konkret):
 * - receivables.status: ENUM -> VARCHAR(20) supaya bisa 'cancelled' (superset, data lama aman).
 * - receivables: kolom pembatalan piutang; receivable_payments: kolom pembatalan (void)
 *   pelunasan -- baris TIDAK dihapus supaya jejak audit utuh (aktif = voided_at IS NULL).
 * - receivables.source_key UNIK (mis. 'booking:123'): 1 booking = 1 piutang. Backfill hanya
 *   baris pertama per kunci; duplikat lama dibiarkan tanpa key + dicatat di log untuk
 *   ditinjau manual (tidak ada data yang dihapus/diubah nominalnya).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE receivables MODIFY status VARCHAR(20) NOT NULL DEFAULT 'unpaid'");
        }

        Schema::table('receivables', function (Blueprint $table) {
            $table->string('source_key')->nullable()->after('source_id');
            $table->unique('source_key');
            $table->timestamp('cancelled_at')->nullable()->after('created_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable()->after('cancelled_by');
            $table->foreignId('cancel_journal_entry_id')->nullable()->after('cancel_reason')->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('created_by');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by');
            $table->foreignId('void_journal_entry_id')->nullable()->after('void_reason')->constrained('journal_entries')->nullOnDelete();
        });

        $seen = [];
        foreach (DB::table('receivables')->whereNotNull('source_type')->whereNotNull('source_id')->orderBy('id')->get(['id', 'source_type', 'source_id']) as $row) {
            $key = "{$row->source_type}:{$row->source_id}";

            if (isset($seen[$key])) {
                Log::warning("Receivable #{$row->id} duplikat sumber '{$key}' (asli: #{$seen[$key]}) -- tidak diberi source_key, mohon ditinjau manual.");

                continue;
            }

            $seen[$key] = $row->id;
            DB::table('receivables')->where('id', $row->id)->update(['source_key' => $key]);
        }
    }

    public function down(): void
    {
        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('void_journal_entry_id');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });

        Schema::table('receivables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancel_journal_entry_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
            $table->dropUnique(['source_key']);
            $table->dropColumn('source_key');
        });

        // Kolom status sengaja TIDAK dikembalikan ke ENUM (VARCHAR superset, aman).
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stok Terbuang (audit 2026-11-03): pembatalan yang menyimpan barisnya (siapa, kapan, alasan) dan taut ke jurnal pembaliknya.
 * Sebelumnya Stok Terbuang yang salah catat tidak bisa dikoreksi sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_write_offs', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('created_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancelled_reason', 500)->nullable()->after('cancelled_by');
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('cancelled_reason')->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_write_offs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancelled_reason']);
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Rekonsiliasi Bank 2026-09-29:
 * - matched_journal_entry_line_id UNIK: 1 baris jurnal hanya boleh dicocokkan ke 1 mutasi bank
 *   (lapisan kedua di bawah lockForUpdate() di service -- mencegah race condition mencocokkan
 *   1 jurnal ke 2 mutasi sekaligus). Dilewati (dengan log) bila ada data lama yang sudah duplikat.
 * - stale_at: ditandai otomatis saat jurnal yang sudah dicocokkan dibalik (reversal) -- baris
 *   tetap berstatus 'matched' (histori tidak diubah diam-diam) tapi ditandai perlu ditinjau ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('bank_statement_lines')
            ->whereNotNull('matched_journal_entry_line_id')
            ->select('matched_journal_entry_line_id')
            ->groupBy('matched_journal_entry_line_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->timestamp('stale_at')->nullable()->after('status');
        });

        if ($duplicates > 0) {
            Log::warning("Unique constraint matched_journal_entry_line_id dilewati: {$duplicates} baris jurnal dicocokkan ke lebih dari 1 mutasi bank -- mohon ditinjau manual.");

            return;
        }

        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->unique('matched_journal_entry_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            try {
                $table->dropUnique(['matched_journal_entry_line_id']);
            } catch (\Throwable $e) {
                // constraint tidak ada (dilewati saat up()) -- abaikan
            }

            $table->dropColumn('stale_at');
        });
    }
};

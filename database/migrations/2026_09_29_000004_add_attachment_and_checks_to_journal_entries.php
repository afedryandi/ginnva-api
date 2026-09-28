<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Jurnal Umum 2026-09-29 (gap enterprise):
 * - journal_entries.attachment: lampiran bukti (path file), opsional.
 * - CHECK constraint di journal_entry_lines sebagai lapisan KEDUA di bawah
 *   validasi aplikasi: debit & kredit tidak negatif dan salah satunya nol.
 *   MySQL >= 8.0.16 menegakkannya. Kalau ada data lama yang melanggar (atau
 *   versi MySQL tidak mendukung), constraint DILEWATI dengan catatan di log
 *   -- migrasi tidak gagal dan tidak mengubah data apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('attachment')->nullable()->after('description');
        });

        $violations = DB::table('journal_entry_lines')
            ->whereRaw('debit < 0 OR credit < 0 OR (debit > 0 AND credit > 0)')
            ->count();

        if ($violations > 0) {
            Log::warning("CHECK constraint journal_entry_lines dilewati: {$violations} baris lama melanggar aturan debit/kredit.");

            return;
        }

        try {
            DB::statement('ALTER TABLE journal_entry_lines ADD CONSTRAINT chk_jel_amounts CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0))');
        } catch (\Throwable $e) {
            Log::warning('CHECK constraint journal_entry_lines dilewati: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE journal_entry_lines DROP CHECK chk_jel_amounts');
        } catch (\Throwable $e) {
            // constraint tidak ada / driver tidak mendukung -- abaikan
        }

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn('attachment');
        });
    }
};

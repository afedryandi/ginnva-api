<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris debit/kredit — minimal 2 baris per journal_entries, total debit
 * HARUS sama dengan total kredit (divalidasi di JournalEntryService,
 * BUKAN CHECK constraint DB — konsisten dengan pola validasi bisnis
 * lain di codebase ini yang selalu di service/model, bukan di skema).
 *
 * chart_of_account_id pakai restrictOnDelete() — akun yang sudah
 * dipakai jurnal TIDAK BOLEH dihapus sama sekali (beda dari
 * finance_categories yang masih boleh dihapus kalau belum dipakai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chart_of_account_id')->constrained()->restrictOnDelete();

            // Persis 1 dari 2 kolom ini yang terisi (>0) per baris —
            // divalidasi di JournalEntryService, bukan skema.
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);

            $table->string('description')->nullable();

            $table->timestamps();

            $table->index('chart_of_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};

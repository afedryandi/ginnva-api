<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekonsiliasi Bank — 1 baris = 1 baris mutasi dari FILE mutasi
 * bank/kas yang diimpor (CSV/Excel), dicocokkan MANUAL/OTOMATIS ke 1
 * baris journal_entry_lines yang sudah tercatat di sistem, supaya
 * admin bisa membuktikan "semua yang ada di rekening/kas fisik SUDAH
 * tercatat di pembukuan" (dan sebaliknya — ketemu transaksi yang lolos
 * belum dicatat sama sekali).
 *
 * chart_of_account_id — akun mana yang direkonsiliasi (1101 Kas di
 * Tangan atau 1102 Kas di Bank, bebas mana pun yang is_cash=true) —
 * TIDAK di-hardcode ke 1 akun tertentu, karena seluruh integrasi
 * otomatis Fase 3 sejauh ini SEMUA memakai 1101 sebagai akun kas
 * default (lihat FinanceTransactionPostingService dkk) — kalau bisnis
 * ini nanti mulai memisah transaksi lewat 1102, rekonsiliasi tetap
 * bisa dipakai untuk akun itu tanpa migrasi ulang.
 *
 * matched_journal_entry_line_id — nullable, satu-satunya penanda
 * status "sudah dicocokkan" (BUKAN kolom status terpisah yang bisa
 * tidak sinkron) — status 'matched'/'unmatched' di bawah cuma
 * TURUNAN dari ini + 'ignored', disimpan eksplisit supaya query
 * filter tabel tidak perlu whereNull/whereNotNull berulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chart_of_account_id')->constrained()->cascadeOnDelete();

            $table->date('statement_date');
            $table->string('description');
            // Positif = uang masuk, negatif = uang keluar — format
            // tunggal (bukan kolom debit/kredit terpisah) karena
            // sebagian besar ekspor mutasi bank pakai 1 kolom nominal
            // bertanda, lebih gampang dipetakan dari CSV apa pun.
            $table->decimal('amount', 14, 2);
            $table->string('external_reference')->nullable();

            $table->foreignId('matched_journal_entry_line_id')->nullable()
                ->constrained('journal_entry_lines')->nullOnDelete();
            $table->enum('status', ['unmatched', 'matched', 'ignored'])->default('unmatched');

            $table->string('import_batch')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['chart_of_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};

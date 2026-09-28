<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Tutup Periode 2026-09-29 (gap enterprise):
 * - accounting_periods.snapshot (JSON): ringkasan angka SAAT ditutup (jumlah jurnal, total debit/kredit,
 *   laba bersih, saldo kas akhir, peringatan yang dikonfirmasi) sebagai pembanding kalau periode dibuka
 *   kembali lalu ditutup lagi.
 * - accounting_period_events: riwayat tutup/buka kembali yang TIDAK ikut terhapus saat baris periode dibuka
 *   kembali (baris accounting_periods dihapus saat reopen, jadi riwayat perlu tabel sendiri).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->json('snapshot')->nullable()->after('notes');
        });

        Schema::create('accounting_period_events', function (Blueprint $table) {
            $table->id();
            $table->date('period_month');
            $table->string('action', 20); // closed | reopened
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['period_month', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_period_events');

        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->dropColumn('snapshot');
        });
    }
};

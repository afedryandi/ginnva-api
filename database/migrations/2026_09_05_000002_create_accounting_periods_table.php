<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tutup Periode — 1 baris = 1 bulan yang SUDAH DITUTUP (bukan status
 * open/closed di tiap bulan — bulan yang TIDAK punya baris di sini
 * otomatis dianggap masih terbuka). Dipakai AccountingPeriodService &
 * JournalEntryService::assertPeriodOpen() untuk memblokir jurnal baru/
 * posting dengan entry_date yang jatuh di bulan yang sudah ditutup.
 *
 * "Buka Kembali" (reopen) MENGHAPUS baris ini (bukan ubah status) —
 * riwayat siapa/kapan ditutup & dibuka kembali cukup lewat activity
 * log (LogsActivity di model), tidak perlu kolom reopened_by/
 * reopened_at terpisah untuk kasus yang seharusnya jarang terjadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->date('period_month')->unique();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};

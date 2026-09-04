<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jurnal Umum — inti pembukuan berpasangan (double-entry), dibangun di
 * atas Bagan Akun (chart_of_accounts). 1 baris di sini = 1 KEJADIAN
 * (mis. "Bayar sewa toko September"), rinciannya (akun mana didebit/
 * dikredit berapa) ada di journal_entry_lines.
 *
 * status draft/posted (BUKAN dihapus setelah posted) — jurnal yang
 * sudah posted TERKUNCI (lihat JournalEntryService::post()), koreksi
 * dilakukan lewat JURNAL PEMBALIK (reference_type='reversal'), bukan
 * edit/hapus langsung — supaya riwayat pembukuan selalu bisa diaudit
 * (tidak ada jejak yang hilang diam-diam), praktik akuntansi standar.
 *
 * store_id NULLABLE (beda dari finance_transactions yang wajib) — ada
 * jurnal yang company-wide (mis. penyusutan aset kantor pusat, modal
 * disetor), tidak semua kejadian keuangan terikat 1 toko.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number')->unique();
            $table->date('entry_date');
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');

            // Penanda asal jurnal — 'manual' (diketik langsung lewat
            // Filament) atau 'reversal' (jurnal pembalik, reference_id
            // menunjuk journal_entries.id yang dibalik). Fase berikutnya
            // (auto-posting dari Booking/Payroll/Transaksi Keuangan) akan
            // menambah nilai baru di sini TANPA perlu migrasi ulang.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->enum('status', ['draft', 'posted'])->default('draft');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['entry_date', 'status']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};

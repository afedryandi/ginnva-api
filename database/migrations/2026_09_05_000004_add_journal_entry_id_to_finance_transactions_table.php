<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link ke Jurnal Umum yang otomatis dibuat saat Transaksi Keuangan ini
 * disimpan (lihat FinanceTransactionPostingService) — dipakai untuk
 * membalik jurnal lama saat transaksi diubah/dihapus (resync/reverse),
 * BUKAN untuk mengedit jurnal itu langsung (jurnal posted tetap
 * terkunci sesuai aturan JournalEntryService).
 *
 * Nullable — transaksi LAMA (dibuat sebelum Fase 3 ada) tidak akan
 * pernah punya link ini sampai transaksinya di-edit ulang (yang
 * memicu backfill otomatis lewat resync()). nullOnDelete: jurnal
 * TIDAK PERNAH dihapus permanen (cuma dibalik), jadi ini murni jaring
 * pengaman skema, bukan skenario yang benar-benar diharapkan terjadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });
    }
};

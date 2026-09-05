<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menghubungkan Kategori Keuangan (Fase 1, sudah dipakai staff toko
 * sehari-hari) ke Bagan Akun (Fase 2) — fondasi integrasi Fase 3:
 * FinanceTransactionPostingService pakai kolom ini untuk tahu akun P&L
 * mana yang harus didebit/dikredit saat 1 Transaksi Keuangan otomatis
 * diposting jadi Jurnal Umum.
 *
 * Nullable — kategori LAMA (dibuat sebelum Fase 3) belum tentu sudah
 * dihubungkan. FinanceTransactionPostingService akan menolak dengan
 * pesan jelas ("hubungkan dulu ke Bagan Akun") kalau kategori yang
 * dipakai transaksi belum diisi kolom ini, bukan diam-diam dilewati.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->foreignId('chart_of_account_id')->nullable()->after('type')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chart_of_account_id');
        });
    }
};

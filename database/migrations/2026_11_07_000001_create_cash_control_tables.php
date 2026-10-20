<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keuangan Fase 4 (2026-11-07): pengendalian kas harian.
 *  - finance_settings: pengaturan keuangan yang diisi pemilik di panel (mis. batas pengeluaran yang cukup disetujui Store Manager);
 *  - payment_accounts: kolom kas kecil (plafon dana tetap, batas per bukti, pemegang kas);
 *  - cash_counts: hitung kas fisik harian per rekening kas dan selisihnya terhadap saldo sistem;
 *  - budgets: anggaran per bulan per akun (opsional per toko) untuk laporan Anggaran vs Realisasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->decimal('float_amount', 15, 2)->nullable()->after('is_default');   // plafon dana kas kecil
            $table->decimal('voucher_limit', 15, 2)->nullable()->after('float_amount'); // batas per bukti tanpa persetujuan
            $table->foreignId('custodian_id')->nullable()->after('voucher_limit')->constrained('users')->nullOnDelete();
        });

        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_account_id')->constrained('payment_accounts');
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->date('count_date');
            $table->decimal('counted_amount', 15, 2);
            $table->decimal('system_balance', 15, 2);
            $table->decimal('difference', 15, 2);
            $table->text('notes')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('adjustment_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('adjusted_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_account_id', 'count_date']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->date('period_month'); // tanggal 1 bulan anggaran
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete(); // null = seluruh perusahaan
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts');
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['period_month', 'store_id', 'chart_of_account_id'], 'budgets_unique_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('cash_counts');

        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custodian_id');
            $table->dropColumn(['float_amount', 'voucher_limit']);
        });

        Schema::dropIfExists('finance_settings');
    }
};

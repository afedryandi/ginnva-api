<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keuangan Fase 1 (2026-11-05): rekening kas dan bank.
 *  - payment_accounts: daftar rekening (kas toko, bank) yang tiap barisnya terhubung ke SATU akun kas di Bagan Akun, opsional per toko, dengan
 *    metode bayar yang diterimanya (tunai/transfer/qris/edc). Tanpa baris ini semua uang tetap ke akun 1101 seperti sebelumnya;
 *  - cash_transfers: pemindahan uang antar akun kas/bank (mis. setor tunai ke bank), dijurnal Debit akun tujuan, Kredit akun asal;
 *  - finance_transactions.payment_account_id dan booking_down_payments.payment_account_id: rekening yang dipakai transaksi, supaya
 *    pembalikan/pengembalian memakai rekening yang sama. Baris lama (null) dianggap akun 1101.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('kind', 10); // kas | bank
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts');
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete(); // null = pusat / semua toko
            $table->string('bank_name', 80)->nullable();
            $table->text('account_number')->nullable(); // terenkripsi di model
            $table->string('account_holder', 120)->nullable();
            $table->json('methods')->nullable(); // metode bayar yang masuk ke rekening ini
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'is_active']);
        });

        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number', 30)->unique();
            $table->foreignId('from_account_id')->constrained('chart_of_accounts');
            $table->foreignId('to_account_id')->constrained('chart_of_accounts');
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('transfer_date');
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('void_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index('transfer_date');
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreignId('payment_account_id')->nullable()->after('finance_category_id')->constrained('payment_accounts')->nullOnDelete();
        });

        Schema::table('booking_down_payments', function (Blueprint $table) {
            $table->foreignId('payment_account_id')->nullable()->after('booking_id')->constrained('payment_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('booking_down_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_account_id');
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_account_id');
        });

        Schema::dropIfExists('cash_transfers');
        Schema::dropIfExists('payment_accounts');
    }
};

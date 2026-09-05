<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hutang Usaha (Accounts Payable) — 1 baris = 1 tagihan dari supplier
 * yang harus dibayar, dengan pelacakan jatuh tempo & pelunasan
 * (BEDA dari PurchaseRequestPostingService yang cuma menaikkan saldo
 * akun 2110 tanpa detail per-tagihan — sejak fitur ini ada, tiap
 * Permohonan Pembelian yang "Terpenuhi" otomatis bikin 1 baris di
 * sini juga, supaya jurnal 2110 dan detail tagihannya tetap sinkron).
 *
 * source_type/source_id — link OPSIONAL ke asal tagihan (mis.
 * 'purchase_request') — nullable karena tagihan JUGA bisa dicatat
 * manual (bukan dari Permohonan Pembelian, mis. tagihan sewa/jasa),
 * konsisten dengan pola reference_type/reference_id di journal_entries.
 *
 * journal_entry_id — jurnal ASLI yang menaikkan saldo 2110 saat
 * tagihan ini dibuat (bukan jurnal pembayarannya, itu ada di
 * payable_payments.journal_entry_id masing-masing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payables', function (Blueprint $table) {
            $table->id();
            $table->string('payable_number')->unique();
            $table->string('supplier_name');
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();

            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->decimal('amount', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->enum('status', ['unpaid', 'partial', 'paid'])->default('unpaid');

            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payables');
    }
};

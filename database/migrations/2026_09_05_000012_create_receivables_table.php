<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Piutang Usaha (Accounts Receivable) — cermin Payable/Hutang Usaha,
 * tapi arahnya kebalik: uang yang MASIH HARUS DITERIMA dari customer
 * (penjualan/booking dengan termin, belum lunas di tempat). Lihat
 * ReceivableService & BookingPostingService untuk alur lengkapnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->string('receivable_number')->unique();
            $table->string('customer_name');
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
        Schema::dropIfExists('receivables');
    }
};

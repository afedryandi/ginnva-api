<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan Fase 2 (2026-11-11): pembayaran yang diterima saat penjualan diproses (Proses Referral), satu baris per pembayaran, supaya
 * pembayaran campur (tunai + transfer) tercatat benar dan jurnal kasnya terpisah per rekening. DP dan pelunasan piutang tetap di
 * tabelnya masing-masing; Buku Pembayaran menyatukan ketiganya lewat kueri, bukan salinan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_settlement_payments')) {
            return;
        }

        Schema::create('booking_settlement_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            // tunai | transfer | qris | edc (kunci Booking::PAYMENT_METHOD_LABELS)
            $table->string('method', 20);
            // Kosong = rekening dipilih otomatis menurut metode dan toko (PaymentAccountResolver).
            $table->foreignId('payment_account_id')->nullable()->constrained('payment_accounts')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('paid_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['booking_id', 'method']);
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_settlement_payments');
    }
};

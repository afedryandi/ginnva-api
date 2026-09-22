<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Breakdown Metode Pembayaran" (audit Majoo, f3) — Booking sebelumnya
 * cuma catat NOMINAL yang diterima (`amount_received`), tidak pernah
 * catat KANAL bayarnya. Diisi saat "Proses Referral" (BookingResource),
 * bareng transaction_amount/amount_received — titik yang sama saat
 * pembayaran itu benar-benar dikonfirmasi kasir.
 *
 * Nullable & tanpa default -- booking lama TIDAK diasumsikan tunai,
 * dibiarkan "Belum Diisi" di breakdown (pola sama seperti
 * ProductSalesReport utk film_product_id yang belum terisi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('payment_method', ['tunai', 'transfer', 'qris', 'edc'])->nullable()->after('amount_received');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};

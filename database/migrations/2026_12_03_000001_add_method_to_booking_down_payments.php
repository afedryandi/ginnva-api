<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metode pembayaran Uang Muka (DP): tunai, transfer, QRIS, atau EDC (kunci yang sama dengan Booking::PAYMENT_METHOD_LABELS). Supaya Buku Pembayaran
 * bisa merinci uang masuk per metode termasuk DP. Kosong untuk DP lama (dicatat sebelum kolom ini ada) dan tampil sebagai "—".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_down_payments', function (Blueprint $table) {
            $table->string('method', 20)->nullable()->after('payment_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('booking_down_payments', function (Blueprint $table) {
            $table->dropColumn('method');
        });
    }
};

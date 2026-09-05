<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nominal yang BENAR-BENAR sudah diterima tunai — dipisah dari
 * transaction_amount (nilai transaksi PENUH) supaya penjualan dengan
 * termin (customer belum lunas di tempat) bisa dilacak sebagai Piutang
 * Usaha, bukan dianggap semua tunai seperti asumsi sebelumnya di
 * BookingPostingService.
 *
 * Nullable — DEFAULT PERILAKU (kalau kasir tidak isi field ini di form
 * "Proses Referral") dianggap SAMA dengan transaction_amount (lunas
 * penuh), sama persis perilaku SEBELUM kolom ini ada — 100% backward
 * compatible, tidak ada booking lama yang tiba-tiba "berpiutang".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('amount_received', 14, 2)->nullable()->after('transaction_amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('amount_received');
        });
    }
};

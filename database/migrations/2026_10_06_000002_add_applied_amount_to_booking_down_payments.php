<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berapa dari tiap DP yang sudah dipakai melunasi pembayaran booking saat
 * diposting (Dr 2140). Sisa (amount - applied_amount) tetap DP yang boleh
 * dikembalikan. Kolom default 0, aman & reversibel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_down_payments', function (Blueprint $t) {
            $t->decimal('applied_amount', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('booking_down_payments', function (Blueprint $t) {
            $t->dropColumn('applied_amount');
        });
    }
};

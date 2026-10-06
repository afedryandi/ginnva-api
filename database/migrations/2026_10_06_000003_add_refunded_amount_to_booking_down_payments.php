<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengembalian SEBAGIAN DP (sisa yang tidak terpakai setelah booking selesai
 * dan diposting). refunded_at tetap penanda DP dikembalikan PENUH; kolom ini
 * mencatat nominal sisa yang sudah dikembalikan. Default 0, aman & reversibel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_down_payments', function (Blueprint $t) {
            $t->decimal('refunded_amount', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('booking_down_payments', function (Blueprint $t) {
            $t->dropColumn('refunded_amount');
        });
    }
};

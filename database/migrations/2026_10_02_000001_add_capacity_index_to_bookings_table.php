<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit alur Booking 2026-10-02 -- hitungan kapasitas (confirmedOverlapCount)
 * dan overview 14 hari memfilter store_id + status + preferred_date; tanpa
 * index gabungan ini query kapasitas scan seluruh booking toko.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['store_id', 'status', 'preferred_date'], 'bookings_store_status_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_store_status_date_index');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit framework 2026-09-14, "Indeks database pada kolom filter umum"
 * — bookings & attendances kekurangan composite index untuk query
 * gabungan store_id + tanggal yang paling sering dipakai laporan
 * (Dashboard, SalesByPeriodReport, PeakSalesTimeReport, BookingStatsWidget,
 * laporan HR). Murni tambah index, TIDAK mengubah data/kolom apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['store_id', 'created_at']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->index(['store_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'created_at']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'date']);
        });
    }
};

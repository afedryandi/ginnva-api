<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 0 rombakan alur Booking, SPK, Memo (2026-11-27): jenis tenaga kendaraan (bensin, listrik, hybrid). Diisi sekali di Booking (dan di
 * "Kendaraan Saya" milik pelanggan); SPK memakainya untuk menampilkan BBM, Battery, atau keduanya. Kosong untuk data lama (SPK lama tetap
 * menampilkan keduanya). Tidak ada data yang diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('vehicle_power', 10)->nullable()->after('vehicle_size');
        });

        Schema::table('customer_vehicles', function (Blueprint $table) {
            $table->string('vehicle_power', 10)->nullable()->after('size_category');
        });
    }

    public function down(): void
    {
        Schema::table('customer_vehicles', function (Blueprint $table) {
            $table->dropColumn('vehicle_power');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('vehicle_power');
        });
    }
};

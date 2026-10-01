<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bagian C, "Klaim Garansi & Maintenance PPF Terhubung ke Slot Booking"
 * (2026-10-01) -- interval (dalam bulan) antar kunjungan maintenance,
 * diisi manual staff saat menerbitkan garansi PPF (sama preseden manual
 * seperti next_service_reminder_at di Booking). Nullable = maintenance
 * belum/tidak diaktifkan untuk garansi ini -- maintenance_quota (sudah
 * ada sejak 2026-09-25) dipakai ulang sebagai jumlah maksimal occurrence,
 * lihat WarrantyMaintenanceSchedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->unsignedSmallInteger('maintenance_interval_months')->nullable()->after('maintenance_quota');
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->dropColumn('maintenance_interval_months');
        });
    }
};

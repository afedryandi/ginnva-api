<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap DIPERBAIKI 2026-09-26 (audit ulang Garansi, fitur Kuota Maintenance)
 * -- SEBELUMNYA kunjungan yang salah catat (salah tanggal/salah pilih
 * garansi) TIDAK BISA dikoreksi sama sekali -- kuota customer berkurang
 * permanen akibat kesalahan staff tanpa jalan resmi mengembalikannya.
 * Kolom ini SENGAJA soft-cancel (baris tetap ada, ditandai batal), bukan
 * hard-delete -- riwayat kesalahan tetap tercatat, dan
 * Warranty::activeMaintenanceVisits() mengecualikan baris yang
 * cancelled_at-nya terisi dari perhitungan kuota terpakai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_maintenance_visits', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('note');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_maintenance_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};

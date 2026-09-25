<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur "Kuota Maintenance" (2026-09-25) -- customer yang instalasi PPF/WF
 * diberi kesempatan datang maintenance sampai N kali (nilai per garansi,
 * diisi staff, nullable = tidak ditawarkan maintenance untuk garansi ini).
 * Kunjungan yang sudah terpakai dihitung dari jumlah baris di
 * warranty_maintenance_visits (lihat migrasi berikutnya), BUKAN kolom
 * counter terpisah -- supaya selalu konsisten dengan riwayat & tidak bisa
 * drift kalau ada race condition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->unsignedInteger('maintenance_quota')->nullable()->after('extension_years');
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->dropColumn('maintenance_quota');
        });
    }
};

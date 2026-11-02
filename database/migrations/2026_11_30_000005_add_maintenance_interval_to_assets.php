<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Servis aset (audit Aset Tetap, 2026-11-02): interval servis per aset (opsional) dan tanggal servis terakhir. "Catat Servis" mengisi
 * tanggal terakhir dan menggeser jadwal berikutnya sebesar interval, sehingga pengingat tidak berulang terus setelah servis dilakukan.
 * Hanya menambah kolom bernilai kosong, aman dijalankan di data yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedSmallInteger('maintenance_interval_days')->nullable()->after('next_maintenance_date');
            $table->date('last_maintenance_date')->nullable()->after('maintenance_interval_days');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['maintenance_interval_days', 'last_maintenance_date']);
        });
    }
};

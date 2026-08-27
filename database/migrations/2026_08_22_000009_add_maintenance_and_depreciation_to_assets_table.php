<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - next_maintenance_date: jadwal maintenance/kalibrasi berkala —
 *   SEBELUMNYA status "Diperbaiki" adalah jalan buntu, tidak ada
 *   pengingat apa pun untuk mengecek aset itu lagi. Diisi manual admin
 *   (sistem tidak tahu jadwal servis alat fisik), dicek harian oleh
 *   App\Console\Commands\NotifyAssetMaintenanceDue.
 * - useful_life_years, salvage_value: dipakai Asset::currentBookValue()
 *   untuk depresiasi garis lurus — SEBELUMNYA cuma ada purchase_cost
 *   mentah tanpa nilai buku saat ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->date('next_maintenance_date')->nullable()->after('purchase_cost');
            $table->unsignedSmallInteger('useful_life_years')->nullable()->after('next_maintenance_date');
            $table->decimal('salvage_value', 15, 2)->nullable()->after('useful_life_years');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['next_maintenance_date', 'useful_life_years', 'salvage_value']);
        });
    }
};

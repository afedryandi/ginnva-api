<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kapasitas layanan ringan per jam (Fase 2 pengerjaan ulang booking, 2026-10-16): berapa mobil layanan ringan (Detailing,
 * Premium Wash) yang bisa dikerjakan BERSAMAAN pada satu slot jam di toko ini. Nullable = pakai bawaan
 * (config booking.light_capacity_default). Sengaja kolom tersendiri: detailing_slot_count adalah kapasitas zona fisik
 * "Detailing & Persiapan" untuk laporan utilisasi, yang juga dipakai tahap persiapan PPF/kaca film.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stores', 'light_capacity_per_slot')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->unsignedTinyInteger('light_capacity_per_slot')->nullable()->after('install_capacity_per_day');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stores', 'light_capacity_per_slot')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->dropColumn('light_capacity_per_slot');
            });
        }
    }
};

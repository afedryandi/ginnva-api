<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simpan link Google Maps asli yang di-paste admin (dipakai untuk isi
 * latitude/longitude otomatis di StoreResource) supaya mobile app bisa
 * langsung buka link itu apa adanya lewat tombol "Buka Peta" — bukan
 * cuma bikin ulang URL pencarian generik dari lat/lng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('maps_url')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('maps_url');
        });
    }
};

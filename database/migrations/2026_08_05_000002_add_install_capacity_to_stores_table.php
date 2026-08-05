<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Berapa mobil yang bisa dikerjakan toko ini per hari (biasanya sama
     * dengan jumlah tim instalasi) — dipakai fitur kapasitas slot booking
     * untuk membatasi berapa banyak booking yang boleh DIKONFIRMASI di
     * tanggal yang sama. Default 3 sesuai ukuran tim instalasi saat ini,
     * per toko boleh beda kalau ke depan ada toko dengan tim lebih besar/
     * kecil.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedTinyInteger('install_capacity_per_day')->default(3)->after('opening_hours');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('install_capacity_per_day');
        });
    }
};

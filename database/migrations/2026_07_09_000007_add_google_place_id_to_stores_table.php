<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Place ID Google Maps toko — dipakai untuk generate link "Tulis Ulasan"
 * yang langsung membuka form review Google untuk lokasi toko itu.
 *
 * Catatan penting: ulasan yang ditulis lewat link Google Maps OTOMATIS
 * juga tercatat di profil Google Business toko — keduanya satu sistem
 * data yang sama (Google sudah menyatukan Maps review & Business Profile
 * review sejak beberapa tahun lalu). Jadi satu Place ID ini sudah cukup
 * untuk kebutuhan "review Google Maps" DAN "review Google Business".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('google_place_id')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('google_place_id');
        });
    }
};

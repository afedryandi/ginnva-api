<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koordinat GPS saat foto di lokasi dinas luar dikirim (permintaan pemilik 2026-12-18): terbaca otomatis dari ponsel, bukan diketik.
 * Dicatat bersama foto sebagai bukti keberadaan; akurasinya (meter) disimpan supaya atasan bisa menilai ketelitiannya. Dinas yang sudah punya foto
 * sebelum kolom ini tetap tanpa koordinat (dibiarkan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_duty_requests', function (Blueprint $table) {
            $table->decimal('location_latitude', 10, 7)->nullable()->after('location_photo_at');
            $table->decimal('location_longitude', 10, 7)->nullable()->after('location_latitude');
            $table->decimal('location_accuracy', 8, 2)->nullable()->after('location_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('field_duty_requests', function (Blueprint $table) {
            $table->dropColumn(['location_latitude', 'location_longitude', 'location_accuracy']);
        });
    }
};

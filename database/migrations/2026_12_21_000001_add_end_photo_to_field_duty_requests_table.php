<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto dinas luar dua kali, seperti absen masuk dan keluar (keputusan pemilik 2026-12-21): foto awal (location_photo*) saat tiba di lokasi dan foto
 * akhir (end_photo*) saat selesai. Foto akhir memakai aturan yang sama dengan foto awal (koordinat GPS di hari dinas, tanpa koordinat untuk susulan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_duty_requests', function (Blueprint $table) {
            $table->string('end_photo')->nullable()->after('location_accuracy');
            $table->timestamp('end_photo_at')->nullable()->after('end_photo');
            $table->decimal('end_latitude', 10, 7)->nullable()->after('end_photo_at');
            $table->decimal('end_longitude', 10, 7)->nullable()->after('end_latitude');
            $table->decimal('end_accuracy', 8, 2)->nullable()->after('end_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('field_duty_requests', function (Blueprint $table) {
            $table->dropColumn(['end_photo', 'end_photo_at', 'end_latitude', 'end_longitude', 'end_accuracy']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto bukti saat tiba di lokasi dinas luar. Dinas yang sudah disetujui baru dianggap hadir kalau karyawan mengirim foto ini di hari dinas;
 * tanpa foto sampai hari itu berakhir, kehadiran dinas sehari penuhnya dibatalkan (menjadi Alpha lewat attendance:mark-absences).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_duty_requests', function (Blueprint $table) {
            $table->string('location_photo')->nullable()->after('description');
            $table->timestamp('location_photo_at')->nullable()->after('location_photo');
        });
    }

    public function down(): void
    {
        Schema::table('field_duty_requests', function (Blueprint $table) {
            $table->dropColumn(['location_photo', 'location_photo_at']);
        });
    }
};

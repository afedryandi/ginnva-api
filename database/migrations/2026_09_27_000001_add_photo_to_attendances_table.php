<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur "Foto Selfie Absensi" (2026-09-27, diminta user) -- staff wajib
 * ambil foto selfie LANGSUNG dari kamera (bukan galeri) saat clock-in/
 * clock-out, di ATAS validasi radius yang sudah ada (bukan pengganti).
 * TIDAK ada deteksi wajah otomatis (keputusan user) -- murni bukti visual
 * yang bisa ditinjau manual admin lewat AttendanceResource.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('clock_in_photo')->nullable()->after('clock_in_is_mocked');
            $table->string('clock_out_photo')->nullable()->after('clock_out_is_mocked');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['clock_in_photo', 'clock_out_photo']);
        });
    }
};

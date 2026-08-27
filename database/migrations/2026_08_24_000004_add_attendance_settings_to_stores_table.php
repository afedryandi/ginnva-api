<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan Absensi per toko — sengaja per-toko (bukan konstanta global)
 * karena Bu Yennie (pemilik CLC, referensi requirement fitur ini) bilang
 * "misal" untuk kedua angka contohnya, tersirat beda kebijakan tiap
 * cabang mungkin berbeda. Kosong (null) berarti pakai default sistem yang
 * dipakai Attendance::DEFAULT_* — lihat App\Models\Attendance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedInteger('attendance_radius_meters')->nullable()->after('longitude');
            $table->unsignedInteger('late_tolerance_minutes')->nullable()->after('attendance_radius_meters');
            $table->decimal('late_deduction_amount', 12, 2)->nullable()->after('late_tolerance_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['attendance_radius_meters', 'late_tolerance_minutes', 'late_deduction_amount']);
        });
    }
};

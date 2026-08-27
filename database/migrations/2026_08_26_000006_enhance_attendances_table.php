<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil audit sistem Absensi (2026-08-26):
 * - entry_type diperlebar: 'alpha' (tidak ada keterangan sama sekali,
 *   dibuat otomatis oleh attendance:mark-absences) dan 'leave' (dibuat
 *   otomatis dari LeaveRequest yang disetujui, cakupan tanggalnya
 *   menyentuh hari itu) — supaya hari tanpa kehadiran SELALU punya baris,
 *   tidak ada lagi "kosong = tidak diketahui statusnya apa".
 * - clock_in_is_mocked/clock_out_is_mocked: dari LocationObject.mocked
 *   (Android) — dipakai menolak absen dari fake-GPS, radius saja tidak
 *   cukup karena koordinat palsu bisa "terlihat" di dalam radius.
 * - early_leave_minutes: pulang cepat, dihitung sama seperti late_minutes
 *   tapi untuk clock_out — SENGAJA tidak dipakai potongan Payroll (di
 *   luar scope yang sudah disepakati), murni data buat admin tinjau.
 * - reviewed_at/reviewed_by: dukung trait Acknowledgeable, supaya entri
 *   telat/di luar radius yang sudah ditinjau admin tidak terus menyala
 *   merah di semua tempat.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE attendances MODIFY entry_type ENUM('clock','manual','field_duty','alpha','leave') NOT NULL DEFAULT 'clock'");

        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('clock_in_is_mocked')->nullable()->after('clock_in_distance_meters');
            $table->boolean('clock_out_is_mocked')->nullable()->after('clock_out_longitude');
            $table->unsignedInteger('early_leave_minutes')->default(0)->after('late_minutes');
            $table->timestamp('reviewed_at')->nullable()->after('note');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['clock_in_is_mocked', 'clock_out_is_mocked', 'early_leave_minutes', 'reviewed_at']);
        });

        DB::statement("ALTER TABLE attendances MODIFY entry_type ENUM('clock','manual','field_duty') NOT NULL DEFAULT 'clock'");
    }
};

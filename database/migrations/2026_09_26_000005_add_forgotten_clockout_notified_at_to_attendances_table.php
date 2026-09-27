<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap DIPERBAIKI 2026-09-26 (audit Absensi Karyawan, "tidak ada
 * notifikasi proaktif untuk lupa clock-out") -- kolom penanda supaya
 * NotifyForgottenClockouts hanya mengirim notifikasi SEKALI per baris,
 * bukan berulang tiap command jalan selama admin belum koreksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->timestamp('forgotten_clockout_notified_at')->nullable()->after('early_leave_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('forgotten_clockout_notified_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penugasan WorkSchedule ke karyawan — audit Majoo vs Ginnva, dibangun
 * 2026-09-22. Menutup SEKALIGUS 3 temuan terkait dari cluster "Jadwal
 * Kerja": bulk-assign ke banyak karyawan (Daftar Jadwal Kerja),
 * "Tanggal Efektif" saat assign baru (bukan retroaktif menimpa histori
 * lama), dan "Riwayat Jadwal Kerja" (log histori penugasan otomatis
 * dari struktur effective_from/effective_to, bukan 1 kolom
 * "jadwal_saat_ini" di tabel user yang menimpa histori).
 *
 * Jadwal AKTIF SEKARANG untuk 1 karyawan = baris dengan
 * effective_from <= hari ini DAN (effective_to IS NULL ATAU
 * effective_to >= hari ini). effective_to NULL = masih berlaku sampai
 * ada penugasan baru (yang otomatis menutup baris lama, lihat
 * EmployeeScheduleAssignment::assignBulk()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'effective_from']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_schedule_assignments');
    }
};

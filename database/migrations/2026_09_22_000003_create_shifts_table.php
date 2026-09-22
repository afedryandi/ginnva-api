<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul "Jadwal Kerja" baru — audit Majoo vs Ginnva, "Master data Shift
 * terpisah dari Jadwal Kerja" (Karyawan / Jadwal Kerja / Daftar Shift),
 * dibangun 2026-09-22 sebagai paket lengkap bersama WorkSchedule &
 * EmployeeScheduleAssignment atas keputusan user.
 *
 * Shift = jam kerja + istirahat yang DIDEFINISIKAN SEKALI, dipakai
 * berulang di berbagai hari/template WorkSchedule (pola normalisasi
 * yang direkomendasikan audit — bukan hardcode jam langsung di
 * template). Per-toko (bukan global) karena jam operasional & pola
 * kerja bisa beda antar cabang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // mis. "Pagi", "Sore"
            $table->time('start_time');
            $table->time('end_time');
            $table->time('break_start_time')->nullable();
            $table->time('break_end_time')->nullable();
            $table->string('color', 7)->nullable(); // hex, utk kalender -- null = pakai warna default sistem
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};

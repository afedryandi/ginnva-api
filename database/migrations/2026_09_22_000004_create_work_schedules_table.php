<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Template Jadwal Kerja Mingguan" (Pola Standar 7-hari) — audit Majoo
 * vs Ginnva, dibangun 2026-09-22. 1 baris = 1 pola mingguan yang bisa
 * dipakai ulang untuk banyak karyawan sekaligus (mis. "Jadwal Toko A —
 * Reguler": Senin-Sabtu shift Pagi, Minggu libur).
 *
 * Kolom `days` JSON array 7 entri {day, shift_id|null} — pola SAMA
 * PERSIS dengan Store::opening_hours (array baris per-hari) yang sudah
 * ada di codebase ini, supaya konsisten. `shift_id: null` di 1 hari =
 * libur di hari itu untuk pola ini. SENGAJA tidak dinormalisasi jadi
 * tabel terpisah (work_schedule_days) -- 7 baris per template tidak
 * butuh query relasional sendiri, JSON cukup & lebih sederhana (sama
 * pertimbangan dengan opening_hours).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // mis. "Jadwal Toko A - Reguler"
            $table->json('days'); // [{day:'mon', shift_id:1}, ..., {day:'sun', shift_id:null}]
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};

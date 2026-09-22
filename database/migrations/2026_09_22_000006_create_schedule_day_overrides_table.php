<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Quick-edit per hari" di kalender Jadwal Kerja — audit Majoo vs
 * Ginnva ("override 1 hari spesifik, mis. tukar shift dadakan, tanpa
 * ubah template jadwal permanen"), dibangun 2026-09-22.
 *
 * 1 baris = 1 override utk 1 karyawan di 1 tanggal SPESIFIK, TERPISAH
 * dari WorkSchedule template-nya (template tidak ikut berubah). Kalau
 * tidak ada baris utk kombinasi user+date, kalender fallback ke pola
 * normal dari EmployeeScheduleAssignment aktif + WorkSchedule::shiftIdFor().
 *
 * `shift_id` NULL = override jadi LIBUR hari itu (bukan "tidak ada
 * override" -- absennya BARIS ini sama sekali yang berarti itu, lihat
 * unique(user_id, date) di bawah).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_day_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_day_overrides');
    }
};

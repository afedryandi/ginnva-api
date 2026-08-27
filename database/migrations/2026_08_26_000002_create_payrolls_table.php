<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1 baris = 1 karyawan per bulan (period_month = tanggal 1 bulan itu).
 * Dihitung dari data Attendance (lihat Payroll::generateForMonth()) —
 * bukan diketik manual, supaya potongan telat selalu konsisten dengan
 * riwayat absensi yang sebenarnya. Fase 1 SENGAJA cuma gaji pokok +
 * potongan telat (tidak ada tunjangan/bonus/kasbon) — lihat percakapan
 * yang menetapkan scope ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('period_month');

            $table->decimal('base_salary', 12, 2);
            $table->unsignedInteger('total_late_minutes')->default(0);
            // Jumlah HARI yang kena potongan (bukan jumlah menit) — lihat
            // Payroll::generateForMonth() untuk cara hitungnya: toleransi
            // bulanan itu budget MENIT, begitu habis, setiap hari telat
            // berikutnya (termasuk hari yang menghabiskan sisa toleransi)
            // kena 1x potongan flat.
            $table->unsignedInteger('late_violation_days')->default(0);
            $table->decimal('deduction_per_violation', 12, 2)->default(0);
            $table->decimal('total_deduction', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2);

            $table->enum('status', ['draft', 'paid'])->default('draft');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};

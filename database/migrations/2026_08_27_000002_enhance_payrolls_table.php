<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil audit modul Penggajian (2026-08-27) — Payroll dibangun SEBELUM
 * Absensi punya entry_type 'alpha'/'leave', jadi awalnya sama sekali
 * tidak tahu soal mangkir. Field baru di sini + logic baru di
 * Payroll::generateForMonth() menutup gap itu:
 * - working_days_in_month: jumlah hari toko buka bulan itu (penyebut
 *   buat hitung gaji per hari, prinsip "no work no pay").
 * - prorated_base_salary: bagian gaji pokok yang BENAR-BENAR diterima
 *   bulan itu — sama dengan base_salary kalau karyawan sudah kerja
 *   penuh sebulan, DIKURANGI kalau baru mulai di tengah bulan
 *   (join_date jatuh di bulan yang sama, proporsional dari situ).
 * - alpha_days/alpha_deduction: hari mangkir tanpa keterangan & potongan
 *   yang dihasilkan (gaji per hari x jumlah hari alpha).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->unsignedInteger('working_days_in_month')->default(0)->after('base_salary');
            $table->decimal('prorated_base_salary', 12, 2)->nullable()->after('working_days_in_month');
            $table->unsignedInteger('alpha_days')->default(0)->after('total_late_minutes');
            $table->decimal('alpha_deduction', 12, 2)->default(0)->after('alpha_days');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['working_days_in_month', 'prorated_base_salary', 'alpha_days', 'alpha_deduction']);
        });
    }
};

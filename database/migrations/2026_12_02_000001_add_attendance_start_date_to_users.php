<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Mulai Wajib Absen": tanggal absensi mulai diwajibkan untuk karyawan ini, terpisah dari Tanggal Mulai Kerja. Sebelum tanggal ini tidak ada
 * Alpha otomatis, pengingat belum absen, dan potongan Alpha di Payroll. Kosong berarti wajib sejak awal (perilaku sebelumnya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('attendance_start_date')->nullable()->after('last_work_date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('attendance_start_date');
        });
    }
};

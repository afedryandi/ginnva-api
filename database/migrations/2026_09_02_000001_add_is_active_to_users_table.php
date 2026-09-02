<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sebelum ini, satu-satunya cara "mengeluarkan" karyawan dari sistem
 * adalah hard delete User — yang cascadeOnDelete ke attendances,
 * leave_requests, payrolls, warning_letters, & contract_extensions
 * (lihat masing-masing migration create table-nya). Artinya menghapus
 * akun karyawan yang resign otomatis memusnahkan SELURUH riwayat
 * absensi/cuti/slip gaji/SP/kontraknya secara permanen — pelanggaran
 * standar HR/enterprise (riwayat penggajian wajib tetap ada untuk
 * kebutuhan pajak/BPJS/audit walau karyawannya sudah tidak aktif).
 *
 * Kolom ini jadi jalur resmi "nonaktifkan" (soft-disable, bukan soft
 * delete penuh) — akun tetap ada beserta semua riwayatnya, tapi tidak
 * bisa lagi dipakai login (lihat Staff\AuthController::login() &
 * User::canAccessPanel()). Default true supaya semua akun existing
 * tetap bisa login seperti biasa setelah migration ini jalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};

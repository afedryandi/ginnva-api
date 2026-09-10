<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No. Karyawan (nomor induk karyawan) — diminta 2026-09-10. Berlaku untuk
 * semua User Ginnva; customer bukan User (guard 'customer' terpisah) dan
 * akun 'partner' sudah dikecualikan dari UserResource, jadi tidak perlu
 * logika kondisional — cukup kolom opsional di users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_number', 50)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_number']);
            $table->dropColumn('employee_number');
        });
    }
};

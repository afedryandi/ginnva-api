<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug diperbaiki 2026-09-28 (audit Daftar Jadwal Kerja): nama template
 * tidak unik di form maupun database. Akan GAGAL kalau data lama sudah
 * punya nama ganda dalam 1 toko -- rename salah satunya dulu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->unique(['store_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'name']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug diperbaiki 2026-09-28 (audit Daftar Shift): nama shift tidak unik
 * di form maupun database, dua "Pagi" di toko yang sama bisa dibuat.
 * Akan GAGAL kalau data lama sudah punya nama ganda dalam 1 toko --
 * rename/hapus salah satunya dulu di menu Daftar Shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unique(['store_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'name']);
        });
    }
};

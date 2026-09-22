<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Master Tipe Karyawan" — audit Majoo vs Ginnva ("bukan enum tetap
 * Tetap/Kontrak/Lepas, tapi tipe kustom yang bisa ditambah admin, mis.
 * 'Freelance Musiman', 'Magang' dengan aturan sendiri"), dibangun
 * 2026-09-22.
 *
 * `has_end_date` = apakah tipe ini WAJIB punya tanggal berakhir kontrak
 * (dipakai UserResource utk tampilkan/wajibkan contract_end_date secara
 * kondisional, bukan selalu tampil seperti sebelumnya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('has_end_date')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_types');
    }
};

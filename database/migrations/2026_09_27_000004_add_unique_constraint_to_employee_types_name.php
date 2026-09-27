<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug diperbaiki 2026-09-27 (audit Tipe Karyawan): sebelumnya "name"
 * cuma divalidasi unik di form Filament (->unique(ignoreRecord: true)),
 * tidak ada constraint di level database -- dua admin submit form
 * "Tambah Tipe" dengan nama sama dalam window yang berdekatan bisa
 * lolos keduanya (race condition ringan, bug integritas data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_types', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('employee_types', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }
};

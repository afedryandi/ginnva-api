<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis kelamin & alamat pelanggan — diminta 2026-09-10 (samakan kolom
 * "Daftar Pelanggan" Majoo). Opsional; diisi admin lewat aksi
 * "Data Pribadi" di CustomerResource (form resource lainnya read-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->enum('gender', ['male', 'female'])->nullable()->after('phone_number');
            $table->string('address', 500)->nullable()->after('gender');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['gender', 'address']);
        });
    }
};

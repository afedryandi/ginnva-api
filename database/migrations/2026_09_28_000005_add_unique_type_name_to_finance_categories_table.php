<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug diperbaiki 2026-09-28 (audit Kategori Keuangan): nama kategori tidak
 * unik di database, hanya divalidasi di form Filament (dan tanpa
 * memperhitungkan tipe). Sekarang unik per (type, name): "Listrik" boleh
 * ada sekali sebagai pemasukan dan sekali sebagai pengeluaran. Akan GAGAL
 * kalau data lama sudah punya nama ganda dalam tipe yang sama -- rename
 * salah satunya dulu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->unique(['type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropUnique(['type', 'name']);
        });
    }
};

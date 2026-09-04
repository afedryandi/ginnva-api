<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori Pemasukan/Pengeluaran — master data (mis. "Sewa Toko",
 * "Listrik & Air", "Booking/Penjualan") supaya laporan per-kategori
 * tetap rapi (bukan teks bebas yang bisa typo). SENGAJA sederhana
 * (nama + tipe doang) — TIDAK ada Chart of Accounts/nomor akun seperti
 * akuntansi double-entry, sesuai keputusan awal modul Keuangan: mulai
 * dari pencatatan pemasukan/pengeluaran sederhana dulu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['in', 'out']);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_categories');
    }
};

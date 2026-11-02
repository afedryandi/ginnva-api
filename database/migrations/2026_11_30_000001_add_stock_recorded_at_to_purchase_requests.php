<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda bahwa penerimaan sebuah Permohonan Pembelian sudah dicatat sebagai stok masuk (audit Daftar Bahan Baku, 2026-11-02): "Tandai
 * Terpenuhi" kini bisa sekaligus menambah stok sejumlah yang diterima, dan penanda ini mencegah orang lain mencatat barang yang sama
 * lagi lewat Catat Stok tanpa tahu. Hanya menambah kolom bernilai kosong, aman dijalankan di data yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->timestamp('stock_recorded_at')->nullable()->after('fulfilled_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('stock_recorded_at');
        });
    }
};

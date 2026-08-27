<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sebelumnya unit_cost cuma 1 angka di raw_materials (harga rata-rata,
 * tidak per pembelian) — begitu harga beli berubah antar pembelian, nilai
 * stok yang dihitung dari current_stock * unit_cost jadi cuma estimasi
 * kasar. Ditambahkan per-batch supaya valuasi stok bisa dihitung dari
 * kuantitas batch yang MASIH ADA dikali harga beli batch itu SENDIRI —
 * lebih akurat, konsisten dengan FIFO yang sudah dipakai untuk konsumsi.
 *
 * Kolom unit_cost di raw_materials TETAP ada — sekarang perannya jadi
 * "harga terakhir/perkiraan", dipakai sebagai nilai default saat "Catat
 * Stok" supaya admin tidak perlu ketik ulang tiap kali harganya sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};

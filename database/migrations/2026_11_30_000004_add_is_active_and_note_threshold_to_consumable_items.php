<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barang Habis Pakai (audit 2026-11-02), sama dengan Bahan Baku: status aktif/nonaktif (barang yang sudah punya riwayat tidak bisa dihapus,
 * jadi yang tidak dipakai lagi dinonaktifkan: hilang dari pemilih dan peringatan, stok dan riwayatnya tetap utuh) dan batas selisih opname
 * per barang (selisih di atasnya wajib catatan; kosong = aturan mati). Hanya menambah kolom, aman dijalankan di data yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumable_items', function (Blueprint $table) {
            $table->decimal('adjustment_note_threshold', 12, 2)->nullable()->after('reorder_point');
            $table->boolean('is_active')->default(true)->after('notes')->index();
        });
    }

    public function down(): void
    {
        Schema::table('consumable_items', function (Blueprint $table) {
            $table->dropColumn(['adjustment_note_threshold', 'is_active']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batas selisih opname per bahan baku (audit Daftar Bahan Baku, 2026-11-02): selisih hasil hitung fisik yang lebih besar dari angka ini
 * (dalam satuan bahan itu sendiri) wajib disertai catatan alasan. Kosong = aturan mati. Hanya menambah kolom bernilai kosong, aman
 * dijalankan di data yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->decimal('adjustment_note_threshold', 12, 2)->nullable()->after('reorder_point');
        });
    }

    public function down(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropColumn('adjustment_note_threshold');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur "Sisa Roll" (RollScrapPool) DIBATALKAN TOTAL 2026-09-15 (diminta
 * user) -- gap arsitektur traceability roll_number vs Garansi (1 roll
 * seharusnya = 1 garansi, tapi pool sisa menggabungkan banyak roll jadi
 * satu tanpa kode tunggal) belum terselesaikan, tim memutuskan tidak
 * jadi dilanjutkan daripada dipaksakan dengan desain yang belum matang.
 *
 * Data yang ada (2 baris pool + 2 movement, dikonfirmasi user cuma data
 * uji coba) SENGAJA ikut dihapus permanen -- BUKAN migrasi yang aman
 * di-rollback, `down()` TIDAK mengembalikan data yang sudah dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('roll_scrap_movements');
        Schema::dropIfExists('roll_scrap_pools');
    }

    public function down(): void
    {
        // Tidak bisa di-rollback -- data yang sudah dihapus tidak
        // dikembalikan. Kalau fitur ini mau dibangun ulang nanti,
        // jalankan lagi migrasi create_roll_scrap_pools_table &
        // create_roll_scrap_movements_table dari awal (tidak dihapus
        // dari riwayat migrasi, cuma tabelnya di-drop lewat migrasi ini).
    }
};

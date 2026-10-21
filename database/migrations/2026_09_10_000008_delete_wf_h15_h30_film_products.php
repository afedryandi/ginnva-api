<?php

use Illuminate\Database\Migrations\Migration;

/**
 * DIJADIKAN NO-OP 2026-10-21. Migrasi ini dulu menghapus WF-H15 & WF-H30 beserta
 * kode gulungan (scroll_codes), pemakaian, resep, harga, dan studi kasusnya.
 * Di production (batch 109, rilis 2026-10-13) itu menghilangkan kode gulungan
 * yang masih dipakai untuk membuat garansi. Produk, 4 kode gulungan, dan tautan
 * item gudang dipulihkan dari cadangan 2026-10-12 pada 2026-10-21.
 *
 * Isi aslinya tidak boleh dijalankan lagi di instalasi mana pun. Nama berkas
 * dipertahankan supaya catatan "Ran" di tabel migrations tetap valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sengaja kosong.
    }

    public function down(): void
    {
        // Tidak ada yang perlu dibatalkan.
    }
};

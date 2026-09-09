<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diminta 2026-09-09 supaya halaman Detail Penjualan/View Booking bisa
 * menampilkan inventori yang terpakai untuk 1 transaksi (produk+seri
 * roll, bahan baku, barang habis pakai) -- semua data itu SUDAH
 * tercatat lengkap di MaterialMemo/MaterialMemoItem (modul Memo Barang),
 * cuma belum pernah terhubung ke Booking (sebelumnya diidentifikasi
 * manual lewat spk_number/vehicle_info, bebas teks).
 *
 * NULLABLE (dikonfirmasi user: opsional, boleh diisi kapan saja, TIDAK
 * wajib saat Memo dibuat) & UNIQUE (dikonfirmasi user: selalu 1
 * booking = 1 memo -- kalau perlu tambah barang di tengah pekerjaan,
 * memo yang SAMA diedit, bukan bikin memo baru).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_memos', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->unique()->after('store_id')
                ->constrained('bookings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('material_memos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Stok Opname" -- keputusan atasan 2026-09-19 (Topik 4, Fase 1,
 * "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"): hitung fisik berkala,
 * dikelompokkan sebagai 1 SESI (bukan cuma "Sesuaikan Stok" satu-satu
 * per item seperti yang sudah ada) supaya tercatat sebagai 1 kejadian
 * opname yang jelas per toko/tanggal.
 *
 * `store_id` SENGAJA WAJIB (bukan nullable seperti stock_write_offs) --
 * ini persis bagian "penanda cabang" yang diminta di Fase 1, hitung
 * fisik inherently dilakukan DI 1 lokasi tertentu.
 *
 * TIDAK ADA posting jurnal otomatis untuk NILAI selisih opname di versi
 * ini (beda dari StockWriteOff yang posting ke akun 6520) -- akun mana
 * yang menyerap selisih POSITIF (ketemu lebih banyak dari catatan
 * sistem) belum ada keputusan resmi (mirip status HPP: butuh konfirmasi
 * kebijakan akuntansi eksplisit, bukan diasumsikan sepihak di sini).
 * Kuantitas stok TETAP disesuaikan lewat RawMaterial::adjustStock()/
 * ConsumableItem::adjustStock() yang sudah ada & teruji -- cuma
 * pengelompokan sesi + pencatatan cabang yang baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('opname_number')->unique();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->date('opname_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opnames');
    }
};

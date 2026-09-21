<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Mutasi Roll Film antar cabang" -- keputusan atasan 2026-09-19 (Topik
 * 4, "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"): bagian TERKECIL & TER-
 * AMAN dari Fase 1 model stok multi-cabang -- ScrollCode SUDAH per-store
 * sejak awal (`scroll_codes.store_id`), jadi mutasinya cuma memindahkan
 * kepemilikan 1 kode gulungan dari 1 store ke store lain, TANPA
 * menyentuh model stok bahan/consumable yang masih pool nasional
 * (itu ditunda ke Fase 2). Pola SAMA PERSIS dengan `asset_transfers`
 * (Asset::transfer, sudah ada) -- lihat ScrollCode::transferTo().
 *
 * Frekuensi mutasi bahan antar cabang dikonfirmasi JARANG (per bulan
 * atau lebih jarang) -- desain SENGAJA sederhana, tanpa approval
 * berjenjang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scroll_code_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scroll_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('to_store_id')->constrained('stores')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scroll_code_transfers');
    }
};

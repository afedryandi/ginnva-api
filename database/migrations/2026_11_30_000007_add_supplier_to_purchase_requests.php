<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemasok yang menyuplai barang dari sebuah Permohonan Pembelian (audit Supplier 2026-11-02). Sebelumnya supplier yang dipilih saat
 * "Tandai Terpenuhi" hanya dipakai membuat tagihan, jadi saat Keuangan mati pilihannya hilang dan tidak ada riwayat pembelian per
 * pemasok. Permohonan lama dibiarkan kosong (tidak ada data untuk ditebak).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('item_id')->constrained('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
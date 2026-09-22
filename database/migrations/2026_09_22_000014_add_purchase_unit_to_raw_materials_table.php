<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Multi-satuan per bahan baku dgn faktor konversi" (audit Majoo, f38),
 * dibangun 2026-09-22 atas keputusan user (contoh nyata: cairan
 * pembersih/coating dibeli per Galon, dipakai per ml).
 *
 * SENGAJA TIDAK mengubah `unit`/`current_stock` yang sudah ada --
 * itu TETAP satuan KONSUMSI/stok (ml), tidak ada ripple effect ke
 * movement/laporan/konsumsi yang sudah ada. `purchase_unit` +
 * `purchase_conversion_factor` murni MEMBANTU INPUT saat "Catat Stok
 * Masuk": staff isi jumlah dalam satuan BELI (mis. 2 Galon) + total
 * harga beli, sistem otomatis konversi ke `unit` dasar (ml) dan hitung
 * harga per satuan dasar -- lihat RawMaterial::convertPurchaseToBaseUnit().
 * Nullable & opsional -- bahan yang satuan beli=satuan pakainya sudah
 * sama (mayoritas) tidak perlu diisi sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->string('purchase_unit')->nullable()->after('unit');
            $table->decimal('purchase_conversion_factor', 12, 4)->nullable()->after('purchase_unit');
        });
    }

    public function down(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropColumn(['purchase_unit', 'purchase_conversion_factor']);
        });
    }
};

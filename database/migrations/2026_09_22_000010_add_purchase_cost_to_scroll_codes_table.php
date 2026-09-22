<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Kolom Laba Kotor per periode" (audit Majoo, f7) — HPP yang benar
 * untuk Ginnva WAJIB termasuk harga bahan baku film (PPF/Kaca Film),
 * yang SEBELUM INI sama sekali tidak punya harga beli tercatat di mana
 * pun (beda dari RawMaterial/ConsumableItem yang sudah punya
 * unit_cost). Keputusan user 2026-09-22: tambah harga beli ke gulungan
 * film DULU, baru Laba Kotor dihitung utuh.
 *
 * `purchase_cost` = harga beli TOTAL untuk 1 gulungan fisik (bukan per
 * meter) -- opsional/nullable, gulungan lama/yang belum diisi tetap
 * dianggap "harga belum diketahui" (BUKAN Rp 0) di laporan HPP. Biaya
 * per meter diturunkan dari ini dibagi total_length_meters (lihat
 * ScrollCode::costPerMeter()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scroll_codes', function (Blueprint $table) {
            $table->decimal('purchase_cost', 14, 2)->nullable()->after('total_length_meters');
        });
    }

    public function down(): void
    {
        Schema::table('scroll_codes', function (Blueprint $table) {
            $table->dropColumn('purchase_cost');
        });
    }
};

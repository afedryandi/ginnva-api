<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sama pola dengan add_unit_cost_to_raw_material_movements — ConsumableItem
 * tidak punya sistem batch (disepakati tidak diperlukan, barang habis
 * pakai tidak kedaluwarsa), tapi harga per kejadian "Catat Masuk" tetap
 * layak dicatat supaya riwayat harga historis bisa direkonstruksi kalau
 * harga beli berubah antar pembelian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};

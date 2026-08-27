<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * unit_cost baru saja ditambahkan ke raw_material_batches (lihat migration
 * add_unit_cost_to_raw_material_batches), tapi riwayat pergerakan
 * (raw_material_movements) — yang jadi rujukan UTAMA staff/admin untuk
 * lihat histori — tidak ikut menyimpannya. Untuk tahu harga beli suatu
 * kejadian "Catat Masuk", user harus buka tab Batch terpisah dan
 * cocokkan tanggal manual. Disimpan salinan (denormalized) di sini juga
 * supaya riwayat movement bisa langsung tampilkan harga tanpa join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};

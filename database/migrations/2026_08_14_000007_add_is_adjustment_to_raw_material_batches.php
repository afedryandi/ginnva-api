<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Beda visual antara batch dari kiriman asli ("Catat Stok Masuk") vs
     * batch "tidak diketahui asalnya" yang dibuat otomatis saat
     * "Sesuaikan Stok" menemukan fisik lebih banyak dari sistem — lihat
     * RawMaterial::adjustStock(). Sebelumnya keduanya kelihatan identik
     * di tampilan batch, padahal asalnya beda.
     */
    public function up(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->boolean('is_adjustment')->default(false)->after('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->dropColumn('is_adjustment');
        });
    }
};

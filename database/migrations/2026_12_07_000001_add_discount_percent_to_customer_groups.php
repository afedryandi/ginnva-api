<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diskon persen per Grup Pelanggan: dipotong dari harga normal (menurut ukuran kendaraan atau flat) untuk produk yang TIDAK punya harga khusus
 * grup. Harga khusus per produk tetap menang. 0 = tanpa diskon, jadi grup yang sudah ada tidak berubah perilakunya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_groups', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('customer_groups', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harga khusus per (produk, grup pelanggan) — audit Majoo f40. SENGAJA
 * flat (bukan matriks per ukuran kendaraan lagi) supaya tetap sederhana
 * sesuai catatan audit ("strukturnya konkret & sederhana, bukan sistem
 * rumit") — kalau grup ini tidak punya override utk produk tertentu,
 * PriceCalculator::priceFor() jatuh balik ke harga normal (matriks
 * ukuran / base_price) seperti biasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('film_product_group_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('film_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_group_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2);
            $table->timestamps();

            $table->unique(['film_product_id', 'customer_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('film_product_group_prices');
    }
};

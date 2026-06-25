<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('film_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name'); // Contoh: Ginnva Ziwei 70
            $table->enum('product_type', ['window_film', 'ppf', 'color_change']);
            $table->decimal('base_price', 15, 2); // Harga dasar sebelum koefisien
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('film_products');
    }
};

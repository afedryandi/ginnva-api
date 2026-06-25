<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->onDelete('cascade');
            $table->foreignId('film_product_id')->constrained('film_products')->onDelete('restrict');
            $table->foreignId('price_rule_id')->constrained('price_rules')->onDelete('restrict');
            
            // Snapshot harga saat transaksi terjadi (audit-trail jika harga master berubah)
            $table->decimal('base_price_snapshot', 15, 2); 
            $table->decimal('coefficient_snapshot', 4, 2);
            $table->decimal('calculated_price', 15, 2); // (base_price * coefficient)
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
    }
};

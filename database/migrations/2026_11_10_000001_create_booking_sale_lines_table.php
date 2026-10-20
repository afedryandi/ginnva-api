<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan Fase 1 (2026-11-10): rincian harga per layanan pada booking. Sebelumnya satu booking hanya punya satu angka
 * (transaction_amount) dan pendapatan antar jenis layanan dibagi rata. Baris ini mencatat nilai SEBENARNYA per layanan sehingga
 * pembagian pendapatan dan laporan per jenis mengikuti nilai itu. Booking lama tidak punya baris dan tetap memakai pembagian rata.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_sale_lines')) {
            return;
        }

        Schema::create('booking_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            // ppf | kaca_film | detailing | premium_wash (kunci BookingRevenueSplitter::PRODUCTS)
            $table->string('service_type', 20);
            $table->foreignId('film_product_id')->nullable()->constrained('film_products')->nullOnDelete();
            $table->string('position', 80)->nullable();
            $table->string('description', 160)->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            // Harga acuan matriks saat baris dibuat (PriceCalculator::priceFor), untuk melihat seberapa jauh harga menyimpang.
            $table->decimal('reference_price', 15, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['booking_id', 'service_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_sale_lines');
    }
};

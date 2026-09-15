<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris produk/jasa per Invoice (lihat migrasi create_invoices_table).
 * film_product_id OPSIONAL -- staff bisa pilih dari katalog Produk PPF/
 * WF (isi harga otomatis) ATAU ketik nama bebas untuk item non-katalog
 * (jasa tambahan, dsb), sama filosofi dengan Booking.service_type yang
 * teks bebas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('film_product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 20)->default('Unit');
            $table->decimal('price', 15, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};

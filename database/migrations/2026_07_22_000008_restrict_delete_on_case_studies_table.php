<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * case_studies.vehicle_id dan film_product_id sebelumnya cascadeOnDelete()
 * — hapus Vehicle/FilmProduct yang dipakai galeri "Case Study" akan
 * menghapus entri galerinya diam-diam. Diganti restrictOnDelete(),
 * konsisten dengan quotations.vehicle_id, scroll_codes.film_product_id,
 * dan quotation_items.film_product_id yang sudah lebih dulu restrict.
 * Try-catch di VehicleResource/FilmProductResource yang sudah ada
 * otomatis ikut menangkap constraint baru ini juga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_studies', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->dropForeign(['film_product_id']);
        });

        Schema::table('case_studies', function (Blueprint $table) {
            $table->foreign('vehicle_id')
                  ->references('id')->on('vehicles')
                  ->restrictOnDelete();

            $table->foreign('film_product_id')
                  ->references('id')->on('film_products')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('case_studies', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->dropForeign(['film_product_id']);
        });

        Schema::table('case_studies', function (Blueprint $table) {
            $table->foreign('vehicle_id')
                  ->references('id')->on('vehicles')
                  ->cascadeOnDelete();

            $table->foreign('film_product_id')
                  ->references('id')->on('film_products')
                  ->cascadeOnDelete();
        });
    }
};

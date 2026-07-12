<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            // Kolom harga belum dipakai — jadikan nullable supaya insert tidak gagal
            $table->foreignId('price_rule_id')->nullable()->change();
            $table->decimal('base_price_snapshot', 15, 2)->nullable()->change();
            $table->decimal('coefficient_snapshot', 4, 2)->nullable()->change();
            $table->decimal('calculated_price', 15, 2)->nullable()->change();

            // Kolom baru
            $table->unsignedInteger('quantity')->default(1)->after('film_product_id');
            $table->text('notes')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'notes']);
        });
    }
};

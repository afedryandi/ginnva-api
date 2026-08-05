<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan store_id ke quotations (lead capture) supaya lead bisa
     * dikaitkan ke toko/dealer tertentu — dipakai untuk scoping
     * store_manager di Filament.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->foreignId('store_id')
                ->nullable()
                ->after('license_plate')
                ->constrained('stores')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};

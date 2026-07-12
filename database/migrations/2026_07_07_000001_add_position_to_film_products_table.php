<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('film_products', function (Blueprint $table) {
            $table->enum('position', ['front', 'side_rear'])
                  ->default('front')
                  ->after('product_type')
                  ->comment('Posisi kaca yang sesuai: front=kaca depan, side_rear=samping & belakang, all=semua posisi');
        });
    }

    public function down(): void
    {
        Schema::table('film_products', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('vehicle_size', ['S', 'M', 'L', 'XL']);
            // Bagian mobil sesuai referensi xmind
            $table->enum('car_part', ['front', 'back', 'side', 'full_set']); 
            // Koefisien pengali. Contoh: 1.0, 1.2, 0.8
            $table->decimal('coefficient', 4, 2)->default(1.00); 
            $table->timestamps();

            // Mencegah duplikasi aturan untuk ukuran dan bagian yang sama
            $table->unique(['vehicle_size', 'car_part']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_rules');
    }
};

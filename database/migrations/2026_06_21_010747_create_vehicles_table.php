<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('brand'); // Contoh: Honda, Toyota
            $table->string('model'); // Contoh: Civic, Alphaard
            $table->enum('size_category', ['S', 'M', 'L', 'XL']); // Menentukan rule harga
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};

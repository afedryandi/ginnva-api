<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log tiap kejadian bahan baku masuk/keluar — BEDA dari
     * inventory_movements: di sini WAJIB ada quantity karena 1 baris
     * bahan baku mewakili banyak unit sekaligus (mis. "20 liter masuk"),
     * bukan 1 unit per baris.
     */
    public function up(): void
    {
        Schema::create('raw_material_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_material_id')->constrained('raw_materials')->cascadeOnDelete();
            $table->enum('type', ['in', 'out']);
            $table->decimal('quantity', 12, 2);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['raw_material_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_material_movements');
    }
};

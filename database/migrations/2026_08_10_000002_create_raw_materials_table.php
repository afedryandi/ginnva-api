<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bahan baku CURAH (adhesive, backing paper, primer, dll) — beda
     * total dari inventory_items yang 1 baris = 1 unit fisik bernomor
     * seri. Di sini 1 baris = 1 JENIS bahan, dilacak lewat KUANTITAS
     * (current_stock) yang naik/turun lewat raw_material_movements,
     * bukan lewat status in_stock/out per unit.
     */
    public function up(): void
    {
        Schema::create('raw_materials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('unit', 20); // satuan: liter, meter, kg, pcs, roll, dll — teks bebas

            $table->decimal('current_stock', 12, 2)->default(0);
            $table->decimal('reorder_point', 12, 2)->nullable(); // ambang stok menipis, untuk peringatan
            $table->decimal('unit_cost', 12, 2)->nullable(); // harga beli per satuan, opsional untuk valuasi stok

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_materials');
    }
};

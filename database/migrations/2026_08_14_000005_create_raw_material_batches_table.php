<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pelacakan per-batch (mis. per tong/drum) untuk bahan baku yang
     * dibeli bertahap dan dipakai FIFO — beda dari current_stock di
     * raw_materials yang cuma total agregat. 1 batch = 1 kali "Catat Stok
     * Masuk", quantity di sini BERKURANG (bukan cuma dicatat) setiap kali
     * ada "Catat Stok Keluar" yang mengonsumsinya, diambil dari batch
     * received_date PALING LAMA duluan (FIFO) — lihat
     * RawMaterial::recordMovement().
     */
    public function up(): void
    {
        Schema::create('raw_material_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_material_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 2); // sisa kuantitas batch ini, berkurang seiring dipakai
            $table->date('received_date');
            $table->date('expiry_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['raw_material_id', 'received_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_material_batches');
    }
};

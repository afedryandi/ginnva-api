<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log setiap kejadian barang keluar/masuk untuk 1 inventory_item —
     * dicatat tiap kali staff scan QR di app mobile dan pilih "Catat
     * Keluar"/"Catat Masuk". Ini jadi riwayat audit trail per kardus.
     *
     * TIDAK ADA kolom quantity — 1 kardus = 1 unit, jadi tiap baris di
     * sini otomatis berarti "1 unit keluar" atau "1 unit masuk", tidak
     * perlu dihitung.
     */
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->enum('type', ['in', 'out']);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['inventory_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat pergerakan pool "Sisa Roll" — sama pola dengan
 * raw_material_movements/consumable_item_movements. 'in' = staff
 * "Kumpulkan Sisa" dari 1 kode gulungan (source_scroll_code_id
 * terisi); 'out' = sisa dipakai untuk instalasi (booking_id opsional
 * kalau tertaut); 'correction' = pembatalan kejadian terakhir yang
 * salah input (RollScrapPool::reverseLastMovement()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roll_scrap_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roll_scrap_pool_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['in', 'out', 'correction']);
            $table->decimal('quantity', 6, 2);
            $table->foreignId('source_scroll_code_id')->nullable()->constrained('scroll_codes')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['roll_scrap_pool_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roll_scrap_movements');
    }
};

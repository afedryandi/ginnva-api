<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_memos', function (Blueprint $table) {
            $table->id();
            $table->string('memo_number')->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // Bebas teks (bukan relasi ke Booking) — sesuai keputusan awal
            // fitur ini: berdiri sendiri, tidak wajib nempel ke booking
            // yang sudah ada di sistem.
            $table->string('vehicle_info')->nullable();
            $table->string('spk_number')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_memos');
    }
};

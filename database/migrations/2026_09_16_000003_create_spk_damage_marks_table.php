<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Titik kerusakan di diagram kondisi kendaraan SPK -- Fase 2 digitalisasi
 * SPK (diminta user 2026-09-16), diisi dari mobile app dengan tap di
 * diagram mobil tampak atas. Posisi disimpan sebagai PERSENTASE (0-100)
 * relatif ke lebar/tinggi diagram, BUKAN pixel absolut -- supaya titiknya
 * tetap presisi di ukuran layar HP apa pun (kecil/besar, portrait beda
 * device) tanpa perlu dikonversi ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spk_damage_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spk_id')->constrained()->cascadeOnDelete();
            $table->decimal('x_percent', 5, 2); // 0.00 - 100.00
            $table->decimal('y_percent', 5, 2);
            $table->enum('code', ['C', 'B', 'P', 'G', 'M', 'OS']);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('spk_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spk_damage_marks');
    }
};

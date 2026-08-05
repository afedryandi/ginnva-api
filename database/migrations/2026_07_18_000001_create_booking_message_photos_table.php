<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * booking_messages.photo_path (kolom lama) cuma nampung 1 foto per pesan.
 * Store manager perlu lampirkan BEBERAPA foto untuk 1 tahap sekaligus
 * (mis. beberapa sudut mobil) — tabel ini nampung foto tambahan per
 * pesan. Kolom photo_path lama TETAP dipertahankan (jangan drop) supaya
 * histori chat yang sudah terkirim sebelum perubahan ini tidak hilang
 * tampilannya — lihat fallback di BookingMessageController::transform().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_message_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_message_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_message_photos');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat per-booking — satu thread per booking, dipakai untuk:
 * 1. Pesan teks biasa antara admin toko <-> customer
 * 2. Update progress instalasi (type=stage) yang admin kirim, lengkap
 *    dengan foto opsional — ini yang jadi dasar progress tracking di
 *    beranda & detail booking mobile app.
 *
 * Sengaja digabung jadi satu tabel (bukan tabel progress terpisah) karena
 * requirement dari product owner: update progress HARUS muncul juga di
 * kolom chat sebagai bagian dari percakapan, bukan sistem terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            $table->enum('sender_type', ['customer', 'admin', 'system']);
            $table->foreignId('sender_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('type', ['text', 'photo', 'stage']);
            $table->text('body')->nullable();

            // Diisi hanya kalau type = 'stage'. Urutan tetap: diterima ->
            // persiapan -> instalasi -> qc -> selesai, sama untuk semua
            // jenis layanan (PPF maupun Window Film).
            $table->enum('stage', ['received', 'preparation', 'installation', 'qc', 'completed'])->nullable();

            $table->string('photo_path')->nullable();

            $table->timestamps();

            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_messages');
    }
};

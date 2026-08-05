<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review internal (bukan Google Maps) — pendekatan hybrid: sentimen +
 * tag aspek spesifik (bukan cuma bintang) + komentar opsional. Kalau
 * sentimen positif, customer diajak juga tulis review di Google Maps
 * (link resmi Google, lihat mobile app) — kalau negatif, cukup tersimpan
 * di sini untuk staff follow-up, TIDAK diarahkan ke Google (supaya tidak
 * melanggar kebijakan "review gating" Google — bukan menyembunyikan yang
 * jelek, cuma tidak aktif mendorong publish keluhan ke publik).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->enum('sentiment', ['positive', 'neutral', 'negative']);
            // Array string tag aspek yang dipilih, mis. ["pelayanan_ramah","harga_worth_it"]
            $table->json('tags')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('followed_up_at')->nullable();
            $table->foreignId('followed_up_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // 1 booking cuma boleh direview sekali.
            $table->unique('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_reviews');
    }
};

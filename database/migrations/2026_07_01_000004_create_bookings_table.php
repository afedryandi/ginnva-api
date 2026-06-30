<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Booking/appointment instalasi ke toko tertentu (我的预约 di mini
     * app referensi). customer_id WAJIB (bukan nullable) — booking hanya
     * bisa dibuat oleh customer yang sudah login, beda dari ProductInquiry
     * yang publik tanpa akun.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_number')->unique(); // BKG-YYYYMM-XXXX

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            // Teks bebas (bukan FK FilmProduct) — booking sering soal
            // "mau pasang film apa" secara umum, customer belum tentu
            // tahu SKU pasti sebelum konsultasi langsung di toko.
            $table->string('service_type');
            $table->date('preferred_date');
            $table->string('preferred_time')->nullable(); // mis. "10:00 - 12:00"
            $table->text('notes')->nullable();

            $table->enum('status', ['pending', 'confirmed', 'completed', 'cancelled'])
                ->default('pending');

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};

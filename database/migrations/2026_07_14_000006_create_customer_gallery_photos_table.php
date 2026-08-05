<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Galeri "showcase" per-customer yang di-upload staff langsung dari
 * Filament — TERPISAH dari galeri personal yang auto-terbentuk dari foto
 * chat booking (lihat BookingMessage.photo_path + my-gallery endpoint).
 * Tidak terikat ke booking tertentu, supaya staff bisa upload foto
 * kapan pun tanpa perlu ada booking aktif — cocok untuk foto hasil akhir
 * yang benar-benar "keren" untuk ditampilkan di beranda mobile app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_gallery_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('image');
            $table->string('caption')->nullable();
            $table->boolean('is_featured')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_gallery_photos');
    }
};

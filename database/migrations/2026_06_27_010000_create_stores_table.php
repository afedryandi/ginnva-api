<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tabel data dealer/toko fisik Ginnva untuk halaman "Lokasi Dealer".
     * Read-only dari sisi API publik — pengisian/perubahan data dilakukan
     * manual lewat database atau Filament admin panel (belum dibangun).
     */
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('city');
            $table->text('address');
            $table->string('phone')->nullable();

            // Teks bebas, contoh: "Senin–Sabtu, 09:00–17:00"
            // Dipilih teks bebas (bukan terstruktur per hari) karena cukup
            // untuk kebutuhan tampilan saat ini dan lebih fleksibel untuk
            // kasus jam buka yang tidak seragam antar cabang.
            $table->string('opening_hours')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Untuk menyembunyikan toko dari listing publik tanpa menghapus
            // datanya (mis. toko baru yang belum resmi buka).
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('city');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
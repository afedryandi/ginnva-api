<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Kendaraan Saya" (Fase 1 pengerjaan ulang booking, 2026-10-15): kendaraan milik pelanggan yang bisa dipakai ulang di
 * booking, SPK, garansi, dan maintenance. Merek/model dipilih dari katalog `vehicles` (ukuran otomatis dari
 * size_category), atau diketik bebas bila tidak ada di katalog (size_category boleh kosong, toko yang menentukan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_vehicles')) {
            return;
        }

        Schema::create('customer_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('brand', 80);
            $table->string('model', 120);
            $table->string('variant', 120)->nullable();
            $table->string('plate', 20);             // tampilan, mis. "B 1234 ABC"
            $table->string('plate_normalized', 15);  // huruf besar tanpa spasi, dipakai untuk keunikan
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color', 40)->nullable();
            $table->enum('size_category', ['S', 'M', 'L', 'XL', 'XXL'])->nullable();
            $table->string('vin', 25)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['customer_id', 'plate_normalized']);
            $table->index(['customer_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_vehicles');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Tarif berbeda per teknisi untuk layanan yang sama" (audit Majoo,
 * f34), dibangun 2026-09-22 atas keputusan user. Sebelumnya
 * `Technician.commission_amount` adalah SATU angka flat, berlaku sama
 * untuk semua jenis pekerjaan teknisi itu. Sekarang bisa di-override
 * per kombinasi teknisi + jenis layanan (mis. Teknisi A: PPF
 * Rp150.000, Kaca Film Rp80.000).
 *
 * `service_type` pakai 4 kategori yang SAMA dengan flag produk Booking
 * (product_ppf/product_kaca_film/product_detailing/product_premium_wash)
 * -- BUKAN per-SKU (film_product_id opsional & belum wajib diisi,
 * granularitas SKU belum bisa diandalkan untuk komisi).
 *
 * Teknisi TANPA baris di tabel ini sama sekali tetap pakai
 * `commission_amount` flat (fallback, backward compatible) -- lihat
 * Technician::commissionForBooking(). Begitu teknisi punya minimal 1
 * baris, SELURUH perhitungan komisinya pindah ke mode per-layanan ini
 * (tidak dicampur dengan flat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_service_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->enum('service_type', ['ppf', 'kaca_film', 'detailing', 'premium_wash']);
            $table->decimal('commission_amount', 12, 2);
            $table->timestamps();

            $table->unique(['technician_id', 'service_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_service_rates');
    }
};

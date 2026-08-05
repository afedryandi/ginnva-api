<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking bisa mencakup Kaca Film DAN PPF sekaligus — tiap produk punya
 * 3 tahap sendiri (beda urutan/nama), baru menyatu di 2 tahap akhir
 * bersama (Quality Check, Serah Terima Unit). `current_stage` (kolom
 * lama) dipakai sebagai tahap "utama": tahap Kaca Film kalau booking ini
 * ada Kaca Film-nya (baik sendirian atau bareng PPF), tahap PPF kalau
 * booking-nya PPF SAJA, atau tahap bersama begitu sudah masuk qc/completed.
 * `secondary_stage` cuma terisi untuk tahap PPF saat booking punya
 * KEDUA produk (progress paralel independen), null selain itu. Lihat
 * Booking::stageColumnFor().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('product_kaca_film')->default(false)->after('service_type');
            $table->boolean('product_ppf')->default(false)->after('product_kaca_film');
            $table->string('secondary_stage')->nullable()->after('current_stage');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['product_kaca_film', 'product_ppf', 'secondary_stage']);
        });
    }
};

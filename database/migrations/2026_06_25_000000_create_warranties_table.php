<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranties', function (Blueprint $table) {
            $table->id();
            $table->string('warranty_code')->unique(); // No. seri garansi, dicari user di /cek-garansi
            $table->string('customer_name');
            $table->string('phone_number');
            $table->string('car_plate');
            $table->string('car_type');
            $table->string('product_series'); // Nama produk yang dipasang, misal "Ziwei 70"
            $table->date('installation_date');
            $table->date('expiry_date');
            $table->string('dealer_name');
            $table->enum('status', ['active', 'expired', 'pending'])->default('active');
            $table->timestamps();

            // Index untuk pencarian cepat saat user submit kode di form cek garansi
            $table->index('warranty_code');
            $table->index('car_plate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranties');
    }
};
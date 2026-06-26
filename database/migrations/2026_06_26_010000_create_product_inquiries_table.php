<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tabel ini menampung minat customer terhadap produk yang BELUM
     * tersedia di Indonesia (Color Change & Architectural Film).
     * Berbeda dengan `quotations`, di sini TIDAK ada vehicle_id atau
     * daftar item produk — cukup data kontak + catatan bebas, karena
     * tujuannya adalah availability inquiry, bukan permintaan harga beli.
     */
    public function up(): void
    {
        Schema::create('product_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('inquiry_number', 20)->unique();

            $table->string('customer_name');
            $table->string('customer_contact'); // bisa nomor telepon atau email

            // Catatan bebas dari customer, termasuk produk apa yang ditanyakan
            // (mis. "Tanya soal Color Change untuk Pajero", "Kapan Architectural Film tersedia?")
            $table->text('message')->nullable();

            // status follow-up oleh tim sales, sama konsepnya dengan quotations.status
            $table->string('status', 20)->default('new');

            // catatan internal tim sales, tidak terlihat oleh customer
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_inquiries');
    }
};
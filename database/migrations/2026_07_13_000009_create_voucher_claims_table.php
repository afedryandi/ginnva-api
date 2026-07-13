<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            // Kode unik per klaim — ditunjukkan ke staff toko saat bayar,
            // beda dengan kode referral partner (kode referral 1 per partner,
            // ini 1 per klaim customer).
            $table->string('code', 20)->unique();
            $table->enum('status', ['active', 'used'])->default('active');
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // 1 akun cuma bisa klaim 1x per kampanye voucher.
            $table->unique(['voucher_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_claims');
    }
};

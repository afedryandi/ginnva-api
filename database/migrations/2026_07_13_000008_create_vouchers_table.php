<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Kampanye" voucher — mis. "Voucher Rp10.000.000 — 200 Pembeli Pertama".
     * Kelangkaannya dari stok (total_stock), bukan tanggal — expires_at
     * opsional untuk kampanye yang butuh keduanya (admin isi kalau perlu).
     */
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('discount_amount', 12, 2);
            $table->unsignedInteger('total_stock');
            // Diturunkan tiap ada klaim baru — dibaca cepat tanpa COUNT(*)
            // ke voucher_claims tiap kali cek stok tersisa.
            $table->unsignedInteger('claimed_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};

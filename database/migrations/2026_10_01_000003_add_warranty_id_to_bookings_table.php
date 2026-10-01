<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bagian C, "Klaim Garansi & Maintenance PPF Terhubung ke Slot Booking"
 * (2026-10-01) -- menandai booking ini sebagai kunjungan maintenance PPF
 * untuk warranty tsb (beda dari warranty_claim_id yang dipakai Bagian B
 * untuk klaim after-sales -- 2 FK ini saling eksklusif, 1 booking cuma
 * pakai salah satu). Mengikuti konvensi voucher_claim_id/warranty_claim_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('warranty_id')->nullable()->after('warranty_claim_id')
                ->constrained('warranties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warranty_id');
        });
    }
};

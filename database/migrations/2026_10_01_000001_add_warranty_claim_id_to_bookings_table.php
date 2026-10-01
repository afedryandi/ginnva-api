<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bagian B, "Klaim Garansi & Maintenance PPF Terhubung ke Slot Booking"
 * (2026-10-01) -- menghubungkan WarrantyClaim ke Booking supaya submit
 * klaim ikut memakan slot kapasitas toko lewat sistem yang sudah ada
 * (Booking::capacityForDate()/fullDatesInRange()), tanpa logika kapasitas
 * baru. Mengikuti persis konvensi voucher_claim_id
 * (add_voucher_claim_id_to_bookings_table.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('warranty_claim_id')->nullable()->after('voucher_claim_id')
                ->constrained('warranty_claims')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warranty_claim_id');
        });
    }
};

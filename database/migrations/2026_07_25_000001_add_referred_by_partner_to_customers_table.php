<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "customer ini direferensikan oleh Partner mana" — dicatat
 * MANUAL oleh admin lewat Filament (dipakai sebelum app rilis, saat
 * sudah ada customer yang mau direferensikan tapi belum bisa booking
 * lewat app). SENGAJA cuma penanda, BUKAN sumber poin otomatis — poin
 * referral Partner tetap baru diproses saat booking customer ini
 * benar-benar selesai lewat aksi "Proses Referral" di BookingResource
 * (lihat ReferralPointService::awardForBooking()), supaya nominal
 * transaksi & idempotency-nya tetap konsisten dengan alur yang sudah
 * ada. Field ini cuma dipakai untuk PRE-FILL kode referral di form itu
 * supaya staff tidak perlu ingat/ketik ulang manual nanti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('referred_by_partner_id')->nullable()
                ->after('referred_by_customer_id')
                ->constrained('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_partner_id');
        });
    }
};

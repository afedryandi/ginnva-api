<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Referral antar-CUSTOMER ("ajak teman") — beda dari referral Partner
 * (bisnis mitra) yang sudah ada. Tiap customer punya kode sendiri
 * (referral_code) yang bisa dibagikan; teman yang daftar & masukin kode
 * itu saat Complete Profile akan tercatat sebagai "diajak oleh"
 * (referred_by_customer_id). Poin bonus untuk pengajak baru cair setelah
 * booking milik teman yang diajak benar-benar selesai & ada nominal
 * transaksinya (lihat ReferralPointService::awardForCustomerReferral()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('referral_code')->nullable()->unique()->after('phone_number');
            $table->foreignId('referred_by_customer_id')->nullable()
                ->after('referral_code')
                ->constrained('customers')->nullOnDelete();
        });

        // Backfill kode untuk customer yang sudah ada, supaya semua akun
        // langsung punya kode referral untuk dibagikan tanpa perlu nunggu
        // dibuka/disimpan ulang.
        Customer::whereNull('referral_code')->orderBy('id')->each(function (Customer $customer) {
            $customer->update(['referral_code' => Customer::generateReferralCode()]);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_customer_id');
            $table->dropColumn('referral_code');
        });
    }
};

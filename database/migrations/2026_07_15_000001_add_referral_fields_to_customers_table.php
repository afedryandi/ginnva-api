<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        //
        // SENGAJA pakai DB::table (bukan model Eloquent Customer) di sini
        // dan di bawah — migrasi ini harus tetap bisa direplay dari nol di
        // instalasi/restore baru kapan pun, walau model Customer sudah
        // berubah setelah migrasi ini ditulis (mis. nambah SoftDeletes).
        // Query lewat Eloquent otomatis kena global scope model versi
        // SEKARANG, yang bisa merujuk kolom (deleted_at) yang belum ada
        // di titik histori migrasi ini — query builder mentah tidak
        // terikat ke definisi model sama sekali.
        DB::table('customers')->whereNull('referral_code')->orderBy('id')
            ->chunkById(500, function ($customers) {
                foreach ($customers as $customer) {
                    do {
                        $code = strtoupper(Str::random(6));
                    } while (DB::table('customers')->where('referral_code', $code)->exists());

                    DB::table('customers')->where('id', $customer->id)->update(['referral_code' => $code]);
                }
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

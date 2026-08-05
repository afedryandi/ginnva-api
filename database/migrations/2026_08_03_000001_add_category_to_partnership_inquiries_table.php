<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambah kategori "sales" (referral sales-advisor GIIAS) ke tabel
     * ini, terpisah dari makna aslinya "franchise" (我要加盟). Baris
     * kategori "sales" dibuat otomatis + real-time oleh
     * GiiasPartnerSignupController langsung dengan status 'deal' dan
     * partner_id terisi (tanpa lewat review admin), berbeda dari alur
     * "franchise" yang tetap manual lewat tombol "Jadikan Partner".
     *
     * car_brand & dealer_name khusus dipakai kategori "sales" ("Sales
     * dari merek mobil apa" / "dari dealer apa" di form Become a
     * Partner) — city dibuat nullable karena kategori "sales" tidak
     * mengisi field ini sama sekali.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE partnership_inquiries MODIFY city VARCHAR(255) NULL");

        Schema::table('partnership_inquiries', function (Blueprint $table) {
            $table->enum('category', ['franchise', 'sales'])->default('franchise')->after('customer_id');
            $table->string('car_brand')->nullable()->after('city');
            $table->string('dealer_name')->nullable()->after('car_brand');
        });
    }

    public function down(): void
    {
        Schema::table('partnership_inquiries', function (Blueprint $table) {
            $table->dropColumn(['category', 'car_brand', 'dealer_name']);
        });

        DB::statement("ALTER TABLE partnership_inquiries MODIFY city VARCHAR(255) NOT NULL");
    }
};

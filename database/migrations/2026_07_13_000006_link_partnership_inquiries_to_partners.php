<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menghubungkan alur "Pengajuan Kemitraan" (form publik, lead-gen) ke
     * "Partnership Referral" (akun partner + kode referral) — status
     * 'deal' menandai pengajuan yang sudah disepakati, dari situ admin
     * bisa langsung generate akun Partner tanpa input ulang data
     * (lihat PartnershipInquiryResource::table() action "Jadikan Partner").
     */
    public function up(): void
    {
        // Ubah enum status via raw SQL — Schema::table()->enum(...)->change()
        // butuh doctrine/dbal dan tetap tidak reliable untuk ALTER ENUM di MySQL.
        DB::statement("ALTER TABLE partnership_inquiries MODIFY status ENUM('new', 'contacted', 'deal', 'rejected') DEFAULT 'new'");

        Schema::table('partnership_inquiries', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('status')
                ->constrained('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('partnership_inquiries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });

        DB::statement("ALTER TABLE partnership_inquiries MODIFY status ENUM('new', 'contacted', 'rejected') DEFAULT 'new'");
    }
};

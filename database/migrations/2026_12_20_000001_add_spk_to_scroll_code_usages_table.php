<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Catat Pemakaian" roll terkait SPK (bukan lagi booking; permintaan pemilik 2026-12-20). spk_id terisi bila SPK dipilih dari daftar atau nomor yang diketik
 * cocok dengan SPK toko roll itu; spk_number menyimpan nomor yang diketik bebas. booking_id tetap ada dan kini diturunkan dari SPK, supaya syarat
 * "SPK selesai butuh pemakaian roll" dan penelusuran per booking tidak berubah. Catatan lama tetap utuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scroll_code_usages', function (Blueprint $table) {
            $table->foreignId('spk_id')->nullable()->after('booking_id')->constrained('spks')->nullOnDelete();
            $table->string('spk_number', 100)->nullable()->after('spk_id');
        });
    }

    public function down(): void
    {
        Schema::table('scroll_code_usages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spk_id');
            $table->dropColumn('spk_number');
        });
    }
};

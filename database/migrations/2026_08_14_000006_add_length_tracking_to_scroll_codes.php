<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pelacakan meter — beda dari usage_count/max_usage (hitung berapa
     * KALI gulungan dirujuk warranty, kasar untuk Window Film ±30 mobil).
     * PPF panjang standar 15m, Window Film 30m, tapi pemakaian per mobil
     * tidak selalu sama (mobil XL bisa butuh >1 gulungan, sisa gulungan
     * kecil ditabung sampai cukup 1 mobil — lihat diskusi WhatsApp Roy/
     * Alyssa). Nullable — opsional, tidak wajib diisi untuk kode lama.
     */
    public function up(): void
    {
        Schema::table('scroll_codes', function (Blueprint $table) {
            $table->decimal('total_length_meters', 6, 2)->nullable()->after('max_usage');
            $table->decimal('remaining_length_meters', 6, 2)->nullable()->after('total_length_meters');
        });
    }

    public function down(): void
    {
        Schema::table('scroll_codes', function (Blueprint $table) {
            $table->dropColumn(['total_length_meters', 'remaining_length_meters']);
        });
    }
};

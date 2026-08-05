<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beda dari PPF (1 gulungan = 1 mobil, langsung ditandai 'used' begitu
 * dipakai), 1 gulungan Window Film bisa dipasang ke kurang lebih 30 mobil
 * (kaca depan & kaca samping/belakang beda gulungan, dipakai berkali-kali
 * lintas banyak warranty). Menandai 'used' di pemakaian PERTAMA (perilaku
 * lama) salah untuk kasus ini — gulungan akan hilang dari pilihan untuk
 * 29 mobil berikutnya yang sebenarnya masih pakai gulungan fisik yang sama.
 *
 * usage_count  = berapa kali gulungan ini sudah dirujuk oleh warranty.
 * max_usage    = kapasitas gulungan (nullable — kalau tidak diisi, admin
 *                yang manual tandai 'used' saat gulungan fisik habis;
 *                kalau diisi, otomatis ditandai 'used' begitu tercapai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scroll_codes', function (Blueprint $table) {
            $table->unsignedInteger('usage_count')->default(0)->after('status');
            $table->unsignedInteger('max_usage')->nullable()->after('usage_count');
        });
    }

    public function down(): void
    {
        Schema::table('scroll_codes', function (Blueprint $table) {
            $table->dropColumn(['usage_count', 'max_usage']);
        });
    }
};

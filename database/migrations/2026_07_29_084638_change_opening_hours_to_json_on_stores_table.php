<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ubah opening_hours dari teks bebas 1 baris ("Senin–Sabtu, 09:00–17:00")
     * jadi JSON array baris per-grup-hari, supaya jam yang beda per hari
     * (mis. Senin–Jumat vs Sabtu vs Minggu libur) bisa ditampilkan rapi
     * per baris di Filament/web/mobile, bukan numpuk jadi satu kalimat.
     * Format tiap baris: {"days": ["mon","tue",...], "open": "08:30",
     * "close": "17:00", "closed": false}.
     *
     * CATATAN: drop+recreate (bukan ->change()) supaya tidak butuh paket
     * doctrine/dbal yang belum terpasang di project ini — konsekuensinya
     * data opening_hours teks bebas yang sudah ada HILANG saat migrasi ini
     * jalan (harus diisi ulang manual lewat Filament, form barunya per
     * toko). Saat ini cuma sedikit toko terdaftar (fase pra-rilis), jadi
     * re-entry manual bukan beban besar — kalau nanti toko sudah banyak,
     * migrasi seperti ini harus disertai script konversi data dulu.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('opening_hours');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->json('opening_hours')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('opening_hours');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->string('opening_hours')->nullable()->after('phone');
        });
    }
};

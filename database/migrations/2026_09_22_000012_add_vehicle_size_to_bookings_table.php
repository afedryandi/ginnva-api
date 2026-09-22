<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Nego harga kasir dengan cap %" (audit Majoo, f31) — dibutuhkan untuk
 * hitung harga ACUAN (matriks FilmProduct::prices per ukuran) yang
 * dibandingkan dengan nominal yang diketik staff saat "Proses
 * Referral", supaya sistem tahu berapa % sebenarnya didiskon. Nullable
 * & opsional -- booking lama/tanpa film_product_id tetap tidak bisa
 * dihitung persentasenya (jatuh ke jalur approval biasa, BUKAN
 * dipaksa lolos tanpa verifikasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('vehicle_size')->nullable()->after('film_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('vehicle_size');
        });
    }
};

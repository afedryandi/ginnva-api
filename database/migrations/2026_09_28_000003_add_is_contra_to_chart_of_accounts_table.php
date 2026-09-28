<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda akun kontra (audit Bagan Akun 2026-09-28): akumulasi penyusutan
 * dan retur penjualan bersaldo normal berlawanan dengan induk/klasifikasinya
 * tapi tersimpan dengan normal_balance klasifikasi (debit untuk aset).
 * Laporan menjumlahkan saldo bertanda sehingga angkanya sudah benar --
 * kolom ini murni PENANDA supaya tampilan/label tidak menyesatkan, tidak
 * mengubah perhitungan apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->boolean('is_contra')->default(false)->after('is_cash');
        });

        DB::table('chart_of_accounts')
            ->whereIn('code', ['1211', '1221', '1231', '1241', '4900'])
            ->update(['is_contra' => true]);
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('is_contra');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menghubungkan 1 Aset ke 2 akun Bagan Akun — dipakai
 * DepreciationPostingService untuk tahu akun mana yang didebit
 * (akun asetnya sendiri, mis. "1210 Peralatan & Mesin" — TIDAK
 * langsung dipakai untuk jurnal, cuma referensi kategori) dan akun
 * KONTRA-ASET mana yang dikredit tiap bulan (mis. "1211 Akumulasi
 * Penyusutan — Peralatan & Mesin").
 *
 * TIDAK di-derive otomatis dari Asset::category (teks bebas, mis.
 * "Elektronik"/"Kendaraan"/"Mesin" — tidak ada pemetaan baku yang aman
 * ke kode akun) — admin pilih manual saat daftarkan/edit aset, sama
 * pola dengan FinanceCategory::chart_of_account_id. Nullable — aset
 * yang belum dihubungkan dilewati (dilaporkan, bukan gagal diam-diam)
 * saat penyusutan bulanan dijalankan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('chart_of_account_id')->nullable()->after('salvage_value')
                ->constrained()->nullOnDelete();
            $table->foreignId('accumulated_depreciation_account_id')->nullable()->after('chart_of_account_id')
                ->constrained('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chart_of_account_id');
            $table->dropConstrainedForeignId('accumulated_depreciation_account_id');
        });
    }
};

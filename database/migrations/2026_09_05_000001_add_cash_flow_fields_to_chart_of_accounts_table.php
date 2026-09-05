<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom pendukung Laporan Arus Kas (metode LANGSUNG, bukan tidak
 * langsung) — dipakai FinancialStatementService::cashFlowStatement():
 *
 * - is_cash: menandai akun mana yang benar-benar "kas & setara kas"
 *   (1101 Kas di Tangan, 1102 Kas di Bank). Semua jurnal yang menyentuh
 *   akun ini itulah yang jadi "arus kas" — tidak di-hardcode ke kode
 *   akun tertentu di kode PHP, supaya kalau nanti ada rekening bank
 *   baru, admin tinggal centang di Bagan Akun tanpa ubah kode.
 *
 * - cash_flow_category: akun NON-KAS diklasifikasi Operasional/
 *   Investasi/Pendanaan — dipakai untuk menentukan arus kas suatu
 *   jurnal termasuk kategori mana (dilihat dari sisi LAWAN akun kas
 *   di jurnal yang sama). Nullable — akun header/tidak relevan
 *   (mis. akun kas itu sendiri) tidak perlu diisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->boolean('is_cash')->default(false)->after('is_postable');
            $table->enum('cash_flow_category', ['operasional', 'investasi', 'pendanaan'])
                ->nullable()->after('is_cash');
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn(['is_cash', 'cash_flow_category']);
        });
    }
};

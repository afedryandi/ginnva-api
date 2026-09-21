<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian PPN dari transaction_amount -- keputusan atasan 2026-09-19
 * (Topik 1, "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"): harga customer
 * SUDAH inclusive PPN 11%, jadi DPP dihitung mundur (DPP = Total / 1.11,
 * PPN = Total - DPP). Lihat Booking::applyPpnBreakdown().
 *
 * Nullable & TIDAK di-backfill untuk booking lama -- keputusan eksplisit
 * "berlaku booking baru saja", booking yang sudah tercatat sebelum fitur
 * ini aktif dibiarkan tanpa rincian PPN (dpp_amount/ppn_amount tetap
 * null selamanya kecuali transaction_amount-nya diedit ulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('dpp_amount', 14, 2)->nullable()->after('transaction_amount');
            $table->decimal('ppn_amount', 14, 2)->nullable()->after('dpp_amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['dpp_amount', 'ppn_amount']);
        });
    }
};

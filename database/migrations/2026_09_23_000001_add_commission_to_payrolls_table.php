<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrasi Komisi Teknisi → Payroll (diminta 2026-09-23) — sebelumnya
 * Payroll::generateForMonth() cuma menarik data Absensi (potongan telat
 * & alpha), komisi teknisi terpisah total dan cuma tampil di Laporan
 * Komisi Teknisi, tidak pernah menambah net_pay. Field baru:
 * - total_commission: total komisi (Technician::commissionForBooking(),
 *   aturan sama persis dgn Laporan Komisi Teknisi) dari booking yang
 *   journal entry-nya (tanggal selesai/terbayar) jatuh di periode
 *   payroll ini — 0 kalau karyawan bukan Technician atau tidak ada job.
 * - has_unrated_commission: true kalau ADA booking milik karyawan ini
 *   di periode tsb yang tarif komisinya belum lengkap diatur (BUKAN
 *   dihitung Rp 0 yang menyesatkan, sama filosofi dgn
 *   Technician::commissionForBooking()/Laporan Komisi Teknisi) — supaya
 *   admin tahu net_pay yang tampil belum termasuk sebagian komisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('total_commission', 12, 2)->default(0)->after('alpha_deduction');
            $table->boolean('has_unrated_commission')->default(false)->after('total_commission');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['total_commission', 'has_unrated_commission']);
        });
    }
};

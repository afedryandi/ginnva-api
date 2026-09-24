<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * f18 "Metrik Utilisasi Bay/Stall Instalasi" (audit Majoo vs Ginnva,
 * disetujui 2026-09-24) — Ginnva tidak punya entitas bay/stall fisik
 * individual di skema (beda dari ReservationUtilizationReport yang
 * cuma pakai 1 angka kapasitas agregat per toko, install_capacity_per_day),
 * jadi laporan zona BARU ini butuh kapasitas per ZONA FISIK: Zona
 * Detailing & Persiapan (ppf_washing/kf_cleaning/kf_heating/ppf_detailing)
 * dan Zona Instalasi & QC (kf_installation/ppf_installation/qc) — lihat
 * App\Services\BayZoneUtilizationService.
 *
 * Jumlah slot BEDA-BEDA per toko (dikonfirmasi pemilik bisnis), jadi
 * TIDAK bisa pakai konstanta/default sistem seperti install_capacity_per_day
 * — nullable = toko itu belum dikonfigurasi, laporan zona akan
 * mengeluarkan pesan "belum diisi" untuk toko itu, BUKAN menganggap
 * kapasitasnya 0 (0 akan salah menyiratkan toko sengaja tidak punya
 * slot sama sekali).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedInteger('detailing_slot_count')->nullable()->after('install_capacity_per_day');
            $table->unsignedInteger('instalasi_qc_slot_count')->nullable()->after('detailing_slot_count');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['detailing_slot_count', 'instalasi_qc_slot_count']);
        });
    }
};

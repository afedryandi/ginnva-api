<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap DIPERBAIKI 2026-09-26 (audit Riwayat Poin Customer) -- adjustment
 * poin MANUAL oleh admin (CreatePointTransaction) sebelumnya tidak
 * menyimpan jejak "siapa" staf yang melakukannya secara terstruktur --
 * cuma description bebas teks. Kolom ini SENGAJA nullable & hanya diisi
 * untuk entri manual (reference_type='manual') -- entri otomatis
 * (booking/warranty/referral/reward) tidak punya "staf pelaku" yang
 * relevan, digerakkan aturan bisnis bukan keputusan admin per-baris.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_transactions', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('reference_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('point_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};

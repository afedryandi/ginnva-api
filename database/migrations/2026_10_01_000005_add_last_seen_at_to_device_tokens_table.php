<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Notifikasi 2026-10-01, gap "standar enterprise" -- SEBELUMNYA tidak
 * ada cara mendeteksi device token yang sudah "diam" (app di-uninstall
 * tanpa logout, dsb) selain menunggu push benar-benar gagal dikirim
 * (DeviceNotRegistered). last_seen_at disentuh tiap kali device melapor
 * (register-token dipanggil tiap app start) -- memungkinkan housekeeping
 * proaktif di masa depan (mis. hapus token yang tidak pernah lapor >90
 * hari) tanpa perlu menunggu push gagal dulu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};

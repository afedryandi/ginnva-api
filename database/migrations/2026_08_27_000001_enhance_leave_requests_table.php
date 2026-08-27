<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil audit modul Izin & Cuti (2026-08-27):
 * - status 'cancelled': staff bisa batalkan pengajuan SENDIRI selama masih
 *   'pending' — beda dari 'rejected' (keputusan admin/atasan), supaya
 *   riwayat tetap jujur soal siapa yang membatalkan & kenapa.
 * - document: lampiran opsional (mis. surat dokter untuk 'sakit').
 *   SENGAJA nullable/opsional, bukan wajib — belum ada kebijakan resmi
 *   yang mewajibkannya, dan mobile app belum ada UI upload-nya (baru
 *   didukung dari sisi Filament admin dulu).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE leave_requests MODIFY status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending'");

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('document')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('document');
        });

        DB::statement("ALTER TABLE leave_requests MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gap DIPERBAIKI 2026-09-25 (audit Garansi, "tidak ada mekanisme
 * revoke/void untuk garansi yang sudah approved") -- SEBELUMNYA
 * satu-satunya jalan membatalkan garansi yang sudah disetujui adalah
 * Delete permanen (khusus isFullAccess), tidak ada status yang tetap
 * menjaga riwayat/audit trail sambil membatalkan keabsahannya.
 *
 * `status` adalah enum('active','expired','pending') sejak migrasi awal
 * (create_warranties_table) -- 'revoked' ditambah ke enum via raw SQL
 * (Blueprint::enum()->change() butuh doctrine/dbal yang tidak ter-install
 * di proyek ini). getStatusAttribute() di model Warranty diprioritaskan
 * mengembalikan 'revoked' SEBELUM cek expired, supaya garansi yang
 * kebetulan revoked SEKALIGUS sudah lewat expiry_date tetap tampil
 * 'revoked' (bukan tertutupi jadi 'expired').
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE warranties MODIFY status ENUM('active', 'expired', 'pending', 'revoked') DEFAULT 'active'");

        Schema::table('warranties', function (Blueprint $table) {
            $table->text('revoke_reason')->nullable()->after('reviewed_at');
            $table->foreignId('revoked_by')->nullable()->after('revoke_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->after('revoked_by');
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['revoke_reason', 'revoked_at']);
        });

        // Baris 'revoked' (kalau ada) diturunkan ke 'active' dulu supaya
        // ALTER enum balik ke 3 nilai lama tidak gagal karena ada data
        // yang tidak lagi valid untuk enum barunya.
        DB::statement("UPDATE warranties SET status = 'active' WHERE status = 'revoked'");
        DB::statement("ALTER TABLE warranties MODIFY status ENUM('active', 'expired', 'pending') DEFAULT 'active'");
    }
};

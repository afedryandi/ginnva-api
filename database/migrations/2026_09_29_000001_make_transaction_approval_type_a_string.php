<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit Persetujuan Transaksi 2026-09-29 (bug kritis): kolom `type` dibuat
 * enum('booking_referral','refund') di migrasi awal, tetapi DP
 * ('booking_down_payment') ditambahkan belakangan (2026-09-19) TANPA migrasi
 * yang memperluas enum -- di MySQL, pengajuan DP dari staff non-full-access
 * gagal "Data truncated". Diubah jadi VARCHAR(40) supaya jenis baru berikutnya
 * tidak butuh ALTER enum lagi (validitas jenis dijaga di aplikasi lewat
 * TransactionApprovalRequest::TYPE_LABELS). Nilai yang ada tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE transaction_approval_requests MODIFY type VARCHAR(40) NOT NULL");
    }

    public function down(): void
    {
        // Sengaja no-op: VARCHAR adalah superset enum lama, dan mengembalikan
        // ke enum akan MENGHAPUS/menolak baris DP yang sah. Kolom dibiarkan string.
    }
};

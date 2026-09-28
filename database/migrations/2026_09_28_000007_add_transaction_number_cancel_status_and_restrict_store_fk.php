<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Audit Transaksi Keuangan 2026-09-28 (gap enterprise):
 * - finance_transactions.transaction_number: nomor bukti unik (TRX-YYYYMM-XXXX),
 *   di-backfill untuk transaksi yang sudah ada.
 * - finance_expense_approvals.status: tambah 'cancelled' (pengaju membatalkan
 *   pengajuan yang masih menunggu).
 * - finance_transactions.store_id: cascadeOnDelete -> restrictOnDelete. Menghapus
 *   toko sebelumnya menghapus semua transaksinya dan menyisakan jurnal yatim;
 *   sekarang database menolak selama toko masih punya transaksi.
 * Tidak menghapus data. Backup manual tetap disarankan (RUNBOOK §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->string('transaction_number', 30)->nullable()->unique()->after('id');
        });

        // Backfill: nomor unik per baris yang sudah ada (bulan mengikuti tanggal transaksi).
        DB::table('finance_transactions')->whereNull('transaction_number')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                do {
                    $candidate = 'TRX-' . date('Ym', strtotime($row->transaction_date)) . '-' . Str::upper(Str::random(4));
                } while (DB::table('finance_transactions')->where('transaction_number', $candidate)->exists());

                DB::table('finance_transactions')->where('id', $row->id)->update(['transaction_number' => $candidate]);
            }
        });

        DB::statement("ALTER TABLE finance_expense_approvals MODIFY status ENUM('pending_manager','pending_direksi','approved','rejected','cancelled') NOT NULL DEFAULT 'pending_manager'");

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
        });
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
        });
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });

        DB::table('finance_expense_approvals')->where('status', 'cancelled')->update(['status' => 'rejected']);
        DB::statement("ALTER TABLE finance_expense_approvals MODIFY status ENUM('pending_manager','pending_direksi','approved','rejected') NOT NULL DEFAULT 'pending_manager'");

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropUnique(['transaction_number']);
            $table->dropColumn('transaction_number');
        });
    }
};

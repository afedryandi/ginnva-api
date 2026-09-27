<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gap standar enterprise (audit Penggajian 2026-09-27): sebelumnya
 * spv_finance sendirian bisa generate DAN markPaid, langsung posting ke
 * Jurnal Umum -- tidak ada approval berjenjang, beda dari pola modul
 * finansial lain sesi ini (Pengeluaran: staff->store_manager->direksi,
 * lihat FinanceTransactionApprovalRequest). Atasan langsung spv_finance
 * adalah direksi (dikonfirmasi user), jadi Payroll cukup 1 tingkat
 * approval (bukan 2 tingkat seperti Pengeluaran) -- spv_finance
 * mengajukan pembayaran, direksi/super_admin yang menyetujui &
 * memposting. isFullAccess() (super_admin/direksi) TETAP boleh langsung
 * "Tandai Dibayar" dari draft tanpa lewat pengajuan -- mereka sudah
 * ujung rantai approval, tidak ada gunanya mengajukan ke diri sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->foreignId('payment_requested_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('payment_requested_at')->nullable()->after('payment_requested_by');
        });

        // enum tidak bisa di-modify langsung lewat Blueprint di semua
        // driver -- pakai raw statement, konsisten dengan migrasi enum
        // lain di proyek ini.
        DB::statement("ALTER TABLE payrolls MODIFY status ENUM('draft', 'pending_approval', 'paid') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        // Turun ke 'draft' dulu supaya tidak ada baris 'pending_approval'
        // yang mentok saat enum dipersempit lagi.
        DB::table('payrolls')->where('status', 'pending_approval')->update(['status' => 'draft']);
        DB::statement("ALTER TABLE payrolls MODIFY status ENUM('draft', 'paid') NOT NULL DEFAULT 'draft'");

        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_requested_by');
            $table->dropColumn('payment_requested_at');
        });
    }
};

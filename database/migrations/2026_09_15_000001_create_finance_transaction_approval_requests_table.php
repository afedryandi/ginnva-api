<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Kontrol & Kepatuhan (diminta user 2026-09-15): approval
 * berjenjang untuk PENGELUARAN (type='out') Transaksi Keuangan --
 * staff toko ajukan -> store_manager approve -> direksi approve
 * (SEMUA nominal wajib sampai direksi, tidak ada ambang skip). Full-
 * access (super_admin/direksi) yang mengajukan sendiri TIDAK lewat
 * tabel ini sama sekali (langsung tercatat, self-approval tidak
 * menambah kontrol) -- lihat FinanceTransactionApprovalService.
 * Pemasukan (type='in') TIDAK terpengaruh, tetap tercatat langsung
 * seperti sebelumnya.
 *
 * Nama tabel SENGAJA dipersingkat jadi "finance_expense_approvals"
 * (bukan "finance_transaction_approval_requests" sesuai nama model) --
 * nama panjang bikin nama constraint foreign key otomatis (mis.
 * "..._manager_approved_by_foreign") kepotong lewat batas identifier
 * MySQL 64 karakter, migrasi gagal di server (ditemukan 2026-09-15).
 * Lihat FinanceTransactionApprovalRequest::$table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_expense_approvals', function (Blueprint $table) {
            $table->id();

            // Data transaksi yang diajukan -- persis field FinanceTransaction
            // (type, finance_category_id, store_id, amount, transaction_date,
            // description, receipt) -- disimpan JSON supaya belum "nyata"
            // jadi FinanceTransaction sampai disetujui direksi.
            $table->json('payload');

            $table->enum('status', ['pending_manager', 'pending_direksi', 'approved', 'rejected'])
                ->default('pending_manager');

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();

            $table->foreignId('manager_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manager_approved_at')->nullable();

            $table->foreignId('direksi_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('direksi_approved_at')->nullable();

            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();

            // Diisi begitu disetujui direksi & FinanceTransaction
            // sungguhan sudah dibuat -- jejak audit penuh dari
            // pengajuan sampai transaksi jadi.
            $table->foreignId('finance_transaction_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_expense_approvals');
    }
};

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Fase 4 — Kontrol & Kepatuhan (2026-09-15): pengajuan pengeluaran
 * Transaksi Keuangan yang menunggu approval berjenjang (store_manager
 * lalu direksi). Lihat migrasi & FinanceTransactionApprovalService.
 */
class FinanceTransactionApprovalRequest extends Model
{
    use LogsActivity;

    // Nama tabel fisik SENGAJA lebih pendek dari nama model --
    // "finance_transaction_approval_requests" (nama default Eloquent
    // dari nama class ini) bikin nama constraint foreign key otomatis
    // kepotong lewat batas identifier MySQL 64 karakter (migrasi gagal
    // di server, ditemukan 2026-09-15). Lihat catatan lengkap di
    // migrasinya.
    protected $table = 'finance_expense_approvals';

    // TIDAK pakai trait HasStoreScope (App\Models\Scopes\StoreScope) --
    // model ini tidak punya kolom store_id sungguhan, cuma di dalam
    // payload JSON (lihat getStoreIdFromPayloadAttribute() di bawah).
    // Scoping store ditangani manual di
    // FinanceTransactionApprovalRequestResource lewat payload->store_id.

    public const STATUS_LABELS = [
        'pending_manager' => 'Menunggu Store Manager',
        'pending_direksi' => 'Menunggu Direksi',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
    ];

    protected $fillable = [
        'payload',
        'status',
        'requested_by',
        'manager_approved_by',
        'manager_approved_at',
        'direksi_approved_by',
        'direksi_approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_note',
        'finance_transaction_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'manager_approved_at' => 'datetime',
        'direksi_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function managerApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_approved_by');
    }

    public function direksiApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'direksi_approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function financeTransaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class);
    }

    /**
     * store_id transaksi yang diajukan -- diambil dari payload JSON,
     * dipakai untuk scoping store_manager (cuma boleh approve
     * pengajuan toko sendiri).
     */
    public function getStoreIdFromPayloadAttribute(): ?int
    {
        return $this->payload['store_id'] ?? null;
    }

    public function isPendingManager(): bool
    {
        return $this->status === 'pending_manager';
    }

    public function isPendingDireksi(): bool
    {
        return $this->status === 'pending_direksi';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'manager_approved_by', 'direksi_approved_by', 'rejected_by', 'rejection_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('finance_transaction_approval_request');
    }
}

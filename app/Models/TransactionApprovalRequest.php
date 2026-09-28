<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Permintaan approval untuk "Proses Referral"/"Proses Refund" dari
 * staff non-full-access (audit framework 2026-09-14, "Segregation of
 * duties finansial") -- lihat migrasi create_transaction_approval_
 * requests_table & App\Filament\Resources\TransactionApprovalRequestResource.
 */
class TransactionApprovalRequest extends Model
{
    use LogsActivity;

    public const TYPE_LABELS = [
        'booking_referral' => 'Proses Referral',
        'refund' => 'Proses Refund',
        'booking_down_payment' => 'Catat Uang Muka (DP)',
    ];

    public const STATUS_LABELS = [
        'pending' => 'Menunggu',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
    ];

    protected $fillable = [
        'type',
        'booking_id',
        'payload',
        'status',
        'requested_by',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected $casts = [
        'payload' => 'array',
        'decided_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function requester(): BelongsTo
    {
        // withDefault: nama tetap tampil walau akun pengaju sudah dihapus.
        return $this->belongsTo(User::class, 'requested_by')->withDefault(['name' => '(pengguna dihapus)']);
    }

    /** Nominal yang diajukan (referral memakai transaction_amount, lainnya amount). */
    public function amount(): float
    {
        return (float) (in_array($this->type, ['refund', 'booking_down_payment'], true)
            ? ($this->payload['amount'] ?? 0)
            : ($this->payload['transaction_amount'] ?? 0));
    }

    /** Ringkasan 1 baris untuk modal konfirmasi approver. */
    public function summaryLine(): string
    {
        $customer = $this->booking?->customer_name ?? $this->booking?->customer?->name ?? null;

        return (self::TYPE_LABELS[$this->type] ?? $this->type)
            . ' — Booking ' . ($this->booking?->booking_number ?? '—')
            . ($customer ? " ({$customer})" : '')
            . ' — Rp' . number_format($this->amount(), 0, ',', '.')
            . ' — diajukan oleh ' . ($this->requester?->name ?? '—');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'decided_by', 'decision_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('transaction_approval_request');
    }
}

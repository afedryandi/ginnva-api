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
        return $this->belongsTo(User::class, 'requested_by');
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ReceivablePayment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'receivable_id',
        'receipt_number',
        'amount',
        'payment_date',
        'journal_entry_id',
        'notes',
        'created_by',
        'voided_at',
        'voided_by',
        'void_reason',
        'void_journal_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'voided_at' => 'datetime',
    ];

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['receivable_id', 'receipt_number', 'amount', 'payment_date', 'journal_entry_id', 'voided_at', 'void_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('receivable_payment')
            ->setDescriptionForEvent(fn (string $eventName) => "Pelunasan piutang #{$this->receivable_id} — {$eventName}");
    }
}

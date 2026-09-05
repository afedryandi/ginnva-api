<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Receivable extends Model
{
    use LogsActivity;

    protected $fillable = [
        'receivable_number',
        'customer_name',
        'store_id',
        'source_type',
        'source_id',
        'amount',
        'amount_paid',
        'due_date',
        'status',
        'journal_entry_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ReceivablePayment::class)->orderBy('payment_date');
    }

    public function remainingAmount(): float
    {
        return round((float) $this->amount - (float) $this->amount_paid, 2);
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'paid'
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    /**
     * Sumber piutang (Booking, kalau ada) — lookup manual, sama pola
     * dengan Payable::resolveSource().
     */
    public function resolveSource(): ?Booking
    {
        return match ($this->source_type) {
            'booking' => Booking::find($this->source_id),
            default => null,
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['customer_name', 'amount', 'amount_paid', 'due_date', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('receivable')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Piutang usaha {$this->receivable_number} ({$this->customer_name}) dicatat",
                'updated' => "Piutang usaha {$this->receivable_number} diubah",
                'deleted' => "Piutang usaha {$this->receivable_number} dihapus",
                default => "Piutang usaha {$this->receivable_number} — {$eventName}",
            });
    }
}

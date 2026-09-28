<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Receivable extends Model
{
    use LogsActivity;

    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — lihat App\Models\Scopes\StoreScope. Pola manual
    // yang sudah ada di ReceivableResource::getEloquentQuery() SENGAJA
    // DIBIARKAN sebagai defense-in-depth.
    use HasStoreScope;

    protected $fillable = [
        'receivable_number',
        'customer_name',
        'customer_id',
        'store_id',
        'source_type',
        'source_id',
        'source_key',
        'amount',
        'amount_paid',
        'due_date',
        'status',
        'journal_entry_id',
        'notes',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'cancel_journal_entry_id',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
        'amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
        if ($this->status === 'cancelled') {
            return 0.0;
        }

        return round((float) $this->amount - (float) $this->amount_paid, 2);
    }

    public function isOverdue(): bool
    {
        return ! in_array($this->status, ['paid', 'cancelled'], true)
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
            ->logOnly(['customer_name', 'customer_id', 'store_id', 'notes', 'amount', 'amount_paid', 'due_date', 'status', 'cancel_reason'])
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

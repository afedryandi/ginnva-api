<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Payable extends Model
{
    use LogsActivity;

    protected $fillable = [
        'payable_number',
        'supplier_name',
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
        return $this->hasMany(PayablePayment::class)->orderBy('payment_date');
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
     * Sumber tagihan (Permohonan Pembelian, kalau ada) — lookup manual,
     * sama pola dengan MaterialMemoItem::resolveItem() (bukan morphTo
     * Eloquent beneran, codebase ini belum pernah pakai morphMap).
     */
    public function resolveSource(): ?PurchaseRequest
    {
        return match ($this->source_type) {
            'purchase_request' => PurchaseRequest::find($this->source_id),
            default => null,
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['supplier_name', 'amount', 'amount_paid', 'due_date', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('payable')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Hutang usaha {$this->payable_number} ({$this->supplier_name}) dicatat",
                'updated' => "Hutang usaha {$this->payable_number} diubah",
                'deleted' => "Hutang usaha {$this->payable_number} dihapus",
                default => "Hutang usaha {$this->payable_number} — {$eventName}",
            });
    }
}

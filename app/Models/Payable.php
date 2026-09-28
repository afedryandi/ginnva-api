<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Payable extends Model
{
    use LogsActivity;

    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — lihat App\Models\Scopes\StoreScope. Pola manual
    // yang sudah ada di PayableResource::getEloquentQuery() SENGAJA
    // DIBIARKAN sebagai defense-in-depth.
    use HasStoreScope;

    protected $fillable = [
        'payable_number',
        'supplier_name',
        'supplier_id',
        'invoice_number',
        'attachment',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
     * Sumber tagihan (Permohonan Pembelian, kalau ada) — lookup manual,
     * sama pola dengan MaterialMemoItem::resolveItem() (bukan morphTo
     * Eloquent beneran, codebase ini belum pernah pakai morphMap).
     */
    public function resolveSource(): PurchaseRequest|RecurringBillTemplate|null
    {
        return match ($this->source_type) {
            'purchase_request' => PurchaseRequest::find($this->source_id),
            // Audit Majoo f48, "Template tagihan rutin" — Payable yang
            // di-generate otomatis menautkan balik ke template asalnya.
            'recurring_bill_template' => RecurringBillTemplate::find($this->source_id),
            default => null,
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['supplier_name', 'supplier_id', 'invoice_number', 'store_id', 'notes', 'amount', 'amount_paid', 'due_date', 'status', 'cancel_reason'])
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

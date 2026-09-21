<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Uang Muka (DP) yang diterima untuk 1 booking -- lihat migrasi
 * create_booking_down_payments_table & DownPaymentService untuk aturan
 * bisnis lengkap (keputusan atasan 2026-09-19, Topik 2).
 */
class BookingDownPayment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'booking_id',
        'amount',
        'received_at',
        'notes',
        'journal_entry_id',
        'refund_journal_entry_id',
        'refunded_at',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'received_at' => 'date',
        'refunded_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Jurnal saat DP ini DITERIMA (Debit Kas / Kredit 2140).
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * Jurnal PEMBALIK saat DP ini dikembalikan -- null selama belum
     * di-refund.
     */
    public function refundJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'refund_journal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRefunded(): bool
    {
        return $this->refunded_at !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['booking_id', 'amount', 'received_at', 'notes', 'refunded_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}

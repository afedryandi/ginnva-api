<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Refund extends Model
{
    use LogsActivity;

    protected $fillable = [
        'refund_number',
        'booking_id',
        'amount',
        'reason',
        'journal_entry_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * Format: REFUND-YYYYMMDD-XXXX (urut per hari), sama pola dengan
     * MaterialMemo::generateMemoNumber().
     */
    public static function generateRefundNumber(): string
    {
        $datePart = now()->format('Ymd');
        $todayCount = static::where('refund_number', 'like', "REFUND-{$datePart}-%")->count();

        return sprintf('REFUND-%s-%04d', $datePart, $todayCount + 1);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['refund_number', 'booking_id', 'amount', 'reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}

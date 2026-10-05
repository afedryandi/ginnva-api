<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pengajuan pembatalan booking yang SUDAH dikonfirmasi oleh customer
 * (keputusan user 2026-10-05) -- staff menyetujui (booking dibatalkan, DP
 * dikembalikan sesuai aturan) atau menolak dengan catatan. Lihat
 * BookingCancellationService.
 */
class BookingCancellationRequest extends Model
{
    use LogsActivity;

    protected $fillable = [
        'booking_id',
        'customer_id',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
        'reminder_count',
        'reminder_sent_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'reason', 'decided_by', 'decision_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('booking_cancellation_request')
            ->setDescriptionForEvent(fn (string $event) => "Pengajuan pembatalan booking #{$this->booking?->booking_number} — {$event}");
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pengajuan ganti tanggal booking oleh customer (keputusan 2026-10-02) --
 * disetujui/ditolak staff toko. Lihat BookingRescheduleService.
 */
class BookingRescheduleRequest extends Model
{
    use LogsActivity;

    protected $fillable = [
        'booking_id',
        'customer_id',
        'requested_date',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected $casts = [
        'requested_date' => 'date',
        'decided_at'     => 'datetime',
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
            ->logOnly(['status', 'requested_date', 'decided_by', 'decision_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('booking_reschedule_request')
            ->setDescriptionForEvent(fn (string $event) => "Pengajuan jadwal ulang booking #{$this->booking?->booking_number} — {$event}");
    }
}

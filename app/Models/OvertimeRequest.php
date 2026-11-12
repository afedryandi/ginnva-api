<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pengajuan lembur karyawan (2026-11-12). Hanya jam yang disetujui (status approved) yang diakui sebagai lembur. Aturan pengajuan dan
 * persetujuan ada di OvertimeRequestService; migrasinya create_overtime_requests_table.
 */
class OvertimeRequest extends Model
{
    use HasStoreScope;
    use LogsActivity;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'store_id',
        'booking_id',
        'date',
        'starts_at',
        'ends_at',
        'minutes',
        'description',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'date' => 'date',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'minutes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** Pekerjaan yang diajukan, untuk tampilan: nomor booking bila ditautkan, ditambah uraian bila ada. */
    public function workLabel(): string
    {
        $parts = array_filter([
            $this->booking ? 'Booking ' . $this->booking->booking_number : null,
            $this->description,
        ]);

        return implode(' — ', $parts) ?: '—';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'starts_at', 'ends_at', 'description', 'review_notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('overtime_request')
            ->setDescriptionForEvent(fn (string $eventName) => "Pengajuan lembur {$this->user?->name} ({$this->date?->format('d M Y')}) — {$eventName}");
    }
}

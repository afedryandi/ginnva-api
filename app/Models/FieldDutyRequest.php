<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pengajuan Dinas Luar karyawan (2026-11-12): bekerja di luar toko (mis. kanvasing), sehari penuh atau sebagian hari dengan rentang jam. Sehari
 * penuh: setelah disetujui, absensi hari itu menjadi Dinas Luar. Sebagian hari: telat dan pulang cepat tidak dihitung pada rentang jam itu. Aturan ada di FieldDutyRequestService; migrasinya create_field_duty_requests_table.
 */
class FieldDutyRequest extends Model
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
        'date',
        'start_time',
        'end_time',
        'description',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Sehari penuh bila jam mulai dan selesai kosong; selain itu dinas sebagian hari. */
    public function isPartialDay(): bool
    {
        return $this->start_time !== null && $this->end_time !== null;
    }

    public function startsAt(): ?\Illuminate\Support\Carbon
    {
        return $this->isPartialDay() ? $this->date->copy()->setTimeFromTimeString($this->start_time) : null;
    }

    public function endsAt(): ?\Illuminate\Support\Carbon
    {
        return $this->isPartialDay() ? $this->date->copy()->setTimeFromTimeString($this->end_time) : null;
    }

    /** Untuk tampilan: "Sehari penuh" atau "10:00–13:00". */
    public function windowLabel(): string
    {
        return $this->isPartialDay() ? substr($this->start_time, 0, 5) . '–' . substr($this->end_time, 0, 5) : 'Sehari penuh';
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'date', 'start_time', 'end_time', 'description', 'review_notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('field_duty_request')
            ->setDescriptionForEvent(fn (string $eventName) => "Pengajuan dinas luar {$this->user?->name} ({$this->date?->format('d M Y')}) — {$eventName}");
    }
}

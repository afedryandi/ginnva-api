<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pengajuan Izin Sebagian Hari (2026-11-18): izin singkat yang tidak memotong apa pun, dalam tiga kategori. Setelah disetujui, menit telat atau
 * pulang cepat yang tercakup jamnya tidak dihitung. Aturan ada di PartialLeaveRequestService; migrasinya create_partial_leave_requests_table.
 */
class PartialLeaveRequest extends Model
{
    use HasStoreScope;
    use LogsActivity;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const CATEGORY_LATE_ARRIVAL = 'late_arrival';
    public const CATEGORY_LEAVE_OFFICE = 'leave_office';
    public const CATEGORY_EARLY_LEAVE = 'early_leave';

    public const CATEGORY_LABELS = [
        self::CATEGORY_LATE_ARRIVAL => 'Terlambat Masuk Kerja',
        self::CATEGORY_LEAVE_OFFICE => 'Keluar Lingkungan Kantor',
        self::CATEGORY_EARLY_LEAVE => 'Pulang Cepat',
    ];

    protected $fillable = [
        'user_id',
        'store_id',
        'date',
        'category',
        'start_time',
        'end_time',
        'reason',
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

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? $this->category;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** Kategori yang mengubah hitungan telat atau pulang cepat; Keluar Lingkungan Kantor hanya tercatat. */
    public function affectsTiming(): bool
    {
        return $this->category !== self::CATEGORY_LEAVE_OFFICE;
    }

    public function startsAt(): \Illuminate\Support\Carbon
    {
        return $this->date->copy()->setTimeFromTimeString($this->start_time);
    }

    public function endsAt(): \Illuminate\Support\Carbon
    {
        return $this->date->copy()->setTimeFromTimeString($this->end_time);
    }

    /**
     * Untuk tampilan menurut kategori: "Masuk paling lambat 10:00", "Pulang mulai 15:00", atau "12:00–14:00".
     */
    public function windowLabel(): string
    {
        $start = substr((string) $this->start_time, 0, 5);
        $end = substr((string) $this->end_time, 0, 5);

        return match ($this->category) {
            self::CATEGORY_LATE_ARRIVAL => "Masuk paling lambat {$end}",
            self::CATEGORY_EARLY_LEAVE => "Pulang mulai {$start}",
            default => "{$start}–{$end}",
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'date', 'category', 'start_time', 'end_time', 'reason', 'review_notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('partial_leave_request')
            ->setDescriptionForEvent(fn (string $eventName) => "Pengajuan izin sebagian hari {$this->user?->name} ({$this->date?->format('d M Y')}) — {$eventName}");
    }
}

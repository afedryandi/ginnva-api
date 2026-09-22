<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Override jadwal 1 hari spesifik untuk 1 karyawan — lihat migrasi
 * create_schedule_day_overrides_table untuk penjelasan lengkap.
 */
class ScheduleDayOverride extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'store_id',
        'date',
        'shift_id',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'date', 'shift_id', 'reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('schedule_day_override')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Override jadwal dibuat untuk {$this->user?->name} ({$this->date?->format('d M Y')})",
                'updated' => "Override jadwal {$this->user?->name} ({$this->date?->format('d M Y')}) diubah",
                'deleted' => "Override jadwal {$this->user?->name} ({$this->date?->format('d M Y')}) dihapus",
                default => "Override jadwal {$this->user?->name} — {$eventName}",
            });
    }
}

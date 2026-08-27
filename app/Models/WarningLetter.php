<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class WarningLetter extends Model
{
    use LogsActivity;

    protected $fillable = [
        'warning_number',
        'user_id',
        'store_id',
        'level',
        'reason',
        'issued_date',
        'valid_until',
        'document',
        'issued_by',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'valid_until' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    protected static function booted(): void
    {
        static::creating(function (WarningLetter $warning) {
            if (empty($warning->warning_number)) {
                $warning->warning_number = static::generateWarningNumber();
            }
        });
    }

    protected static function generateWarningNumber(): string
    {
        do {
            $candidate = 'SP-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (static::where('warning_number', $candidate)->exists());

        return $candidate;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['level', 'reason', 'issued_date', 'valid_until'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('warning_letter')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Surat Peringatan #{$this->warning_number} untuk {$this->user?->name} diterbitkan",
                'updated' => "Surat Peringatan #{$this->warning_number} diubah",
                'deleted' => "Surat Peringatan #{$this->warning_number} dihapus",
                default   => "Surat Peringatan #{$this->warning_number} — {$eventName}",
            });
    }
}

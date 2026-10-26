<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Kenaikan atau penurunan harga produk film (persen) yang berlaku otomatis pada tanggal efektif. Lihat ScheduledPriceChangeService.
 */
class ScheduledPriceChange extends Model
{
    use LogsActivity;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_SCHEDULED => 'Terjadwal',
        self::STATUS_APPLIED => 'Sudah berlaku',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    public const ROUNDING_OPTIONS = [
        0 => 'Tanpa pembulatan (rupiah penuh)',
        100 => 'Bulatkan ke Rp100 terdekat',
        1000 => 'Bulatkan ke Rp1.000 terdekat',
        10000 => 'Bulatkan ke Rp10.000 terdekat',
    ];

    protected $fillable = [
        'name', 'effective_date', 'percent', 'rounding_step', 'product_ids', 'include_group_prices',
        'status', 'applied_at', 'affected_prices', 'notes', 'created_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'percent' => 'decimal:2',
        'rounding_step' => 'integer',
        'product_ids' => 'array',
        'include_group_prices' => 'boolean',
        'applied_at' => 'datetime',
        'affected_prices' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Terjadwal dan tanggal efektifnya sudah tiba. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED)->whereDate('effective_date', '<=', today()->toDateString());
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'effective_date', 'percent', 'rounding_step', 'include_group_prices', 'status', 'affected_prices'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('scheduled_price_change')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Perubahan harga \"{$this->name}\" dijadwalkan",
                'updated' => "Perubahan harga \"{$this->name}\" diubah",
                'deleted' => "Perubahan harga \"{$this->name}\" dihapus",
                default => "Perubahan harga \"{$this->name}\" — {$eventName}",
            });
    }
}

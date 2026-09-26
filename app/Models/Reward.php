<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * LogsActivity ditambahkan 2026-09-26 (audit Katalog Reward) --
 * SEBELUMNYA tidak ada sama sekali, padahal points_cost/stock/is_active
 * adalah field sensitif (mirip alasan LogsActivity ditambahkan ke
 * FilmProduct 2026-09-22) yang berdampak langsung ke ekspektasi customer
 * di katalog.
 */
class Reward extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'description',
        'image',
        'points_cost',
        'stock',
        'is_active',
    ];

    protected $casts = [
        'points_cost' => 'integer',
        'stock'       => 'integer',
        'is_active'   => 'boolean',
    ];

    public function redemptions(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }

    public function isRedeemable(): bool
    {
        return $this->is_active && ($this->stock === null || $this->stock > 0);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'points_cost', 'stock', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('reward')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Reward \"{$this->name}\" ditambahkan",
                'updated' => "Reward \"{$this->name}\" diubah",
                'deleted' => "Reward \"{$this->name}\" dihapus",
                default   => "Reward \"{$this->name}\" — {$eventName}",
            });
    }
}

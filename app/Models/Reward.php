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

    public const TYPE_ITEM = 'item';
    public const TYPE_VOUCHER = 'voucher';

    protected $fillable = [
        'name',
        'type',
        'description',
        'image',
        'points_cost',
        'stock',
        'voucher_discount',
        'voucher_valid_days',
        'is_active',
    ];

    protected $casts = [
        'points_cost'        => 'integer',
        'stock'              => 'integer',
        'voucher_discount'   => 'decimal:2',
        'voucher_valid_days' => 'integer',
        'is_active'          => 'boolean',
    ];

    /** Reward bertipe voucher: menukar poin langsung menerbitkan voucher diskon ke akun customer. */
    public function isVoucher(): bool
    {
        return $this->type === self::TYPE_VOUCHER;
    }

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
            ->logOnly(['name', 'type', 'points_cost', 'stock', 'voucher_discount', 'voucher_valid_days', 'is_active'])
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

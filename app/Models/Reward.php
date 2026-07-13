<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reward extends Model
{
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
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    protected $fillable = [
        'name',
        'description',
        'discount_amount',
        'total_stock',
        'claimed_count',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'total_stock'     => 'integer',
        'claimed_count'   => 'integer',
        'expires_at'      => 'datetime',
        'is_active'       => 'boolean',
    ];

    public function claims(): HasMany
    {
        return $this->hasMany(VoucherClaim::class);
    }

    public function remainingStock(): int
    {
        return max(0, $this->total_stock - $this->claimed_count);
    }

    public function isClaimable(): bool
    {
        if (! $this->is_active || $this->remainingStock() < 1) {
            return false;
        }

        return ! $this->expires_at || $this->expires_at->isFuture();
    }
}

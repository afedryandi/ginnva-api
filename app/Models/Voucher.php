<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Voucher extends Model
{
    use LogsActivity;

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

    /**
     * Jejak perubahan kampanye (nominal, stok, kedaluwarsa, aktif/nonaktif). claimed_count sengaja tidak dicatat: sudah ada
     * jejak per klaim di VoucherClaim.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'description', 'discount_amount', 'total_stock', 'expires_at', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('voucher');
    }

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

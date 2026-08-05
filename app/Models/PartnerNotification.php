<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerNotification extends Model
{
    protected $fillable = ['partner_id', 'title', 'body', 'data', 'read_at'];

    protected $casts = [
        'data'    => 'array',
        'read_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function reads(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PartnerNotificationRead::class);
    }

    public function isReadBy(int $partnerId): bool
    {
        if ($this->partner_id !== null) {
            return $this->read_at !== null;
        }

        return $this->reads()->where('partner_id', $partnerId)->exists();
    }

    public function scopeForPartner($query, int $partnerId)
    {
        return $query->where(function ($q) use ($partnerId) {
            $q->where('partner_id', $partnerId)
              ->orWhereNull('partner_id');
        });
    }
}

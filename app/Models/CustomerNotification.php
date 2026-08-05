<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerNotification extends Model
{
    protected $fillable = ['customer_id', 'title', 'body', 'data', 'read_at'];

    protected $casts = [
        'data'    => 'array',
        'read_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reads(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerNotificationRead::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Beda dari isRead() — itu cuma valid untuk notif bertarget
     * (customer_id terisi). Untuk broadcast (customer_id null), status
     * baca per customer disimpan di tabel customer_notification_reads,
     * bukan di kolom read_at baris ini (lihat catatan migration-nya).
     */
    public function isReadBy(int $customerId): bool
    {
        if ($this->customer_id !== null) {
            return $this->read_at !== null;
        }

        return $this->reads()->where('customer_id', $customerId)->exists();
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where(function ($q) use ($customerId) {
            $q->where('customer_id', $customerId)
              ->orWhereNull('customer_id');
        });
    }
}

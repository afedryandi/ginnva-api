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

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where(function ($q) use ($customerId) {
            $q->where('customer_id', $customerId)
              ->orWhereNull('customer_id');
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointTransaction extends Model
{
    protected $fillable = [
        'customer_id',
        'type',
        'points',
        'description',
        'reference_type',
        'reference_id',
        // Gap ditutup 2026-09-26 (audit Riwayat Poin Customer) -- lihat
        // migrasi add_created_by_to_point_transactions_table.
        'created_by',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

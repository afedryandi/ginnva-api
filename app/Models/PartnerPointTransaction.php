<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerPointTransaction extends Model
{
    protected $fillable = [
        'partner_id',
        'type',
        'points',
        'description',
        'reference_type',
        'reference_id',
        // Gap ditutup 2026-09-26 (audit Riwayat Poin Partner) -- lihat
        // migrasi add_created_by_to_partner_point_transactions_table.
        'created_by',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

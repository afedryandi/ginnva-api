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
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}

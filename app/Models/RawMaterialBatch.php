<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawMaterialBatch extends Model
{
    protected $fillable = [
        'raw_material_id',
        'quantity',
        'received_date',
        'expiry_date',
        'is_adjustment',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'received_date' => 'date',
        'expiry_date' => 'date',
        'is_adjustment' => 'boolean',
    ];

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

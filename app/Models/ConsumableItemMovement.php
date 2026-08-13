<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumableItemMovement extends Model
{
    protected $fillable = [
        'consumable_item_id',
        'type',
        'quantity',
        'note',
        'user_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    public function consumableItem(): BelongsTo
    {
        return $this->belongsTo(ConsumableItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

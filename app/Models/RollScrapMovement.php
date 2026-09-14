<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RollScrapMovement extends Model
{
    protected $fillable = [
        'roll_scrap_pool_id',
        'type',
        'quantity',
        'source_scroll_code_id',
        'booking_id',
        'note',
        'user_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(RollScrapPool::class, 'roll_scrap_pool_id');
    }

    public function sourceScrollCode(): BelongsTo
    {
        return $this->belongsTo(ScrollCode::class, 'source_scroll_code_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

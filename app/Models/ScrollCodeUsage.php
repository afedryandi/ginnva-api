<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrollCodeUsage extends Model
{
    protected $fillable = [
        'scroll_code_id',
        'meters',
        'note',
        'user_id',
    ];

    protected $casts = [
        'meters' => 'decimal:2',
    ];

    public function scrollCode(): BelongsTo
    {
        return $this->belongsTo(ScrollCode::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

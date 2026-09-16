<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpkDamageMark extends Model
{
    protected $fillable = [
        'spk_id',
        'x_percent',
        'y_percent',
        'code',
        'note',
    ];

    protected $casts = [
        'x_percent' => 'decimal:2',
        'y_percent' => 'decimal:2',
    ];

    public function spk(): BelongsTo
    {
        return $this->belongsTo(Spk::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpkChecklistItem extends Model
{
    public const CATEGORY_LABELS = [
        'pekerjaan' => 'Uraian Pekerjaan',
        'extra_service' => 'Extra Services',
        'perlengkapan' => 'Perlengkapan Kendaraan',
    ];

    protected $fillable = [
        'spk_id',
        'category',
        'label',
        'is_checked',
        'sort_order',
    ];

    protected $casts = [
        'is_checked' => 'boolean',
    ];

    public function spk(): BelongsTo
    {
        return $this->belongsTo(Spk::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingQcResultItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['booking_qc_result_id', 'service_key', 'label', 'is_required', 'passed', 'note'];

    protected $casts = [
        'is_required' => 'boolean',
        'passed' => 'boolean',
    ];

    public function result(): BelongsTo
    {
        return $this->belongsTo(BookingQcResult::class, 'booking_qc_result_id');
    }
}

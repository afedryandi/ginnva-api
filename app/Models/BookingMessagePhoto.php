<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingMessagePhoto extends Model
{
    protected $fillable = ['booking_message_id', 'path'];

    public function bookingMessage(): BelongsTo
    {
        return $this->belongsTo(BookingMessage::class);
    }
}

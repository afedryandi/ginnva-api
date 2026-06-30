<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Booking extends Model
{
    protected $fillable = [
        'booking_number',
        'customer_id',
        'store_id',
        'service_type',
        'preferred_date',
        'preferred_time',
        'notes',
        'status',
    ];

    protected $casts = [
        'preferred_date' => 'date',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            if (empty($booking->booking_number)) {
                $booking->booking_number = static::generateBookingNumber();
            }
        });
    }

    protected static function generateBookingNumber(): string
    {
        do {
            $candidate = 'BKG-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (static::where('booking_number', $candidate)->exists());

        return $candidate;
    }
}

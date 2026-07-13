<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VoucherClaim extends Model
{
    protected $fillable = [
        'voucher_id',
        'customer_id',
        'code',
        'status',
        'booking_id',
        'used_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public static function generateCode(): string
    {
        do {
            $code = 'VC' . strtoupper(Str::random(6));
        } while (self::where('code', $code)->exists());

        return $code;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pembayaran yang diterima saat penjualan booking diproses (Penjualan Fase 2, 2026-11-11). Dibuat dan diganti hanya lewat
 * BookingSettlementPaymentService.
 */
class BookingSettlementPayment extends Model
{
    protected $fillable = ['booking_id', 'method', 'payment_account_id', 'amount', 'paid_at', 'received_by'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'date',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}

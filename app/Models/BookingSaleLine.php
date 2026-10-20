<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris rincian harga pada booking (Penjualan Fase 1, 2026-11-10). line_total selalu dihitung ulang saat disimpan:
 * quantity x unit_price - discount_amount, tidak pernah diisi dari luar.
 */
class BookingSaleLine extends Model
{
    public const SERVICE_TYPES = [
        'ppf' => 'PPF',
        'kaca_film' => 'Kaca Film',
        'detailing' => 'Detailing',
        'premium_wash' => 'Premium Wash',
    ];

    protected $fillable = [
        'booking_id', 'service_type', 'film_product_id', 'position', 'description',
        'quantity', 'unit_price', 'discount_amount', 'line_total', 'reference_price', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'reference_price' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (BookingSaleLine $line) {
            $line->line_total = round((float) $line->quantity * (float) $line->unit_price - (float) $line->discount_amount, 2);
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function filmProduct(): BelongsTo
    {
        return $this->belongsTo(FilmProduct::class)->withTrashed();
    }
}

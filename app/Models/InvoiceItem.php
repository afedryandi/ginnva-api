<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'film_product_id',
        'name',
        'quantity',
        'unit',
        'price',
        'discount_percent',
        'total',
        'note',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function filmProduct(): BelongsTo
    {
        // withTrashed() (audit Daftar Produk 2026-09-25) -- lihat catatan
        // di Booking::filmProduct().
        return $this->belongsTo(FilmProduct::class)->withTrashed();
    }
}

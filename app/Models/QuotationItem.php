<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationItem extends Model
{
    protected $fillable = [
        'quotation_id',
        'film_product_id',
        'quantity',
        'notes',
        // price_rule_id, base_price_snapshot, coefficient_snapshot, calculated_price
        // belum dipakai — harga belum ditentukan oleh Ginnva Indonesia.
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }

    public function filmProduct()
    {
        return $this->belongsTo(FilmProduct::class);
    }
}
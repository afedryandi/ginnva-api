<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationItem extends Model
{
    protected $fillable = [
        'quotation_id',
        'film_product_id',
        'price_rule_id',
        'base_price_snapshot',
        'coefficient_snapshot',
        'calculated_price',
    ];

    protected $casts = [
        'base_price_snapshot' => 'decimal:2',
        'coefficient_snapshot' => 'decimal:2',
        'calculated_price' => 'decimal:2',
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }

    public function filmProduct()
    {
        return $this->belongsTo(FilmProduct::class);
    }

    public function priceRule()
    {
        return $this->belongsTo(PriceRule::class);
    }
}
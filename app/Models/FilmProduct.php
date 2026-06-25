<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FilmProduct extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'product_type',
        'base_price',
        'is_active',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function quotationItems()
    {
        return $this->hasMany(QuotationItem::class);
    }
}
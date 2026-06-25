<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceRule extends Model
{
    protected $fillable = [
        'vehicle_size',
        'car_part',
        'coefficient',
    ];

    protected $casts = [
        'coefficient' => 'decimal:2',
    ];

    public function quotationItems()
    {
        return $this->hasMany(QuotationItem::class);
    }
}
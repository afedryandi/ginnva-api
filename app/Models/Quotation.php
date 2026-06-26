<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $fillable = [
        'quotation_number',
        'vehicle_id',
        'customer_name',
        'customer_phone',
        'license_plate',
        'status',
        'message',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function items()
    {
        return $this->hasMany(QuotationItem::class);
    }
}
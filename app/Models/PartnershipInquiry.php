<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnershipInquiry extends Model
{
    protected $fillable = [
        'customer_id',
        'category',
        'applicant_name',
        'phone_number',
        'email',
        'city',
        'car_brand',
        'dealer_name',
        'message',
        'status',
        'notes',
        'partner_id',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }
}

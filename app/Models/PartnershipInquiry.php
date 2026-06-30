<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnershipInquiry extends Model
{
    protected $fillable = [
        'customer_id',
        'applicant_name',
        'phone_number',
        'email',
        'city',
        'message',
        'status',
        'notes',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}

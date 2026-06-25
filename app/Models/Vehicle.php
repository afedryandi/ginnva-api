<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $fillable = [
        'brand',
        'model',
        'size_category',
    ];

    public function quotations()
    {
        return $this->hasMany(Quotation::class);
    }
}
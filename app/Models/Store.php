<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'city',
        'address',
        'phone',
        'opening_hours',
        'latitude',
        'longitude',
        'google_place_id',
        'is_active',
    ];

    protected $casts = [
        'latitude'  => 'float',
        'longitude' => 'float',
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function warranties()
    {
        return $this->hasMany(Warranty::class);
    }

    public function quotations()
    {
        return $this->hasMany(Quotation::class);
    }

    public function technicians()
    {
        return $this->hasMany(Technician::class);
    }
}
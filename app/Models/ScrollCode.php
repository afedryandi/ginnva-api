<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScrollCode extends Model
{
    protected $fillable = [
        'code',
        'film_product_id',
        'store_id',
        'status',
        'allocated_at',
        'used_at',
        'warranty_code',
    ];

    protected $casts = [
        'allocated_at' => 'datetime',
        'used_at'      => 'datetime',
    ];

    public function filmProduct()
    {
        return $this->belongsTo(FilmProduct::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}

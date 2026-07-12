<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedDate extends Model
{
    protected $fillable = ['store_id', 'date', 'reason'];

    protected $casts = ['date' => 'date'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}

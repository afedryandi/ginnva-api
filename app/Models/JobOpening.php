<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobOpening extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'department',
        'location',
        'type',
        'description',
        'requirements',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'requirements' => 'array',
        'is_published' => 'boolean',
        'sort_order'   => 'integer',
    ];
}

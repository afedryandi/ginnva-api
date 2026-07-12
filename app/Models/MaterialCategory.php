<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialCategory extends Model
{
    protected $fillable = ['name', 'sort_order'];

    public function materials()
    {
        return $this->hasMany(Material::class);
    }
}

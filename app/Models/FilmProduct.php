<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FilmProduct extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'product_type',
        'position',
        'base_price',
        'is_active',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'is_active'  => 'boolean',
    ];

    public function quotationItems()
    {
        return $this->hasMany(QuotationItem::class);
    }

    /**
     * Master Resep (BOM) — bahan standar per 1x pemasangan produk ini.
     * Diminta 2026-09-10. Lihat FilmProductRecipeItem.
     */
    public function recipeItems()
    {
        return $this->hasMany(FilmProductRecipeItem::class);
    }

    public function caseStudies()
    {
        return $this->hasMany(CaseStudy::class);
    }
}
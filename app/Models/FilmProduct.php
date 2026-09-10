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
     * Produk "Detailing" — SATU layanan umum (dikonfirmasi user
     * 2026-09-10), dibuat lewat migrasi 2026_09_10_000002 (SKU
     * SVC-DETAILING). Dipakai auto-isi Memo Barang saat booking
     * ber-product_detailing.
     */
    public static function detailing(): ?self
    {
        return static::where('product_type', 'detailing')->first();
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
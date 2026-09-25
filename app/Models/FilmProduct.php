<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * LogsActivity ditambahkan 2026-09-22 (audit Majoo, f30) — sebelum ini
 * perubahan harga/nama/status aktif produk sama sekali tidak punya
 * jejak audit (siapa ubah apa kapan, sebelum/sesudah), padahal harga
 * adalah data yang sering diubah & berdampak langsung ke penjualan.
 *
 * SoftDeletes ditambahkan 2026-09-25 (audit Daftar Produk, gap "standar
 * enterprise") — sebelumnya hanya restrictOnDelete() DB-level yang
 * mencegah hard-delete produk yang masih dipakai quotation/booking/
 * warranty lama (cukup aman, tapi produk yang staff mau "hapus" dari
 * katalog aktif TETAP tersimpan utuh untuk riwayat, bukan pola formal
 * seperti Customer). Delete lewat Filament sekarang soft-delete.
 */
class FilmProduct extends Model
{
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'sku',
        'name',
        'product_type',
        'position',
        'base_price',
        'is_active',
        'tracks_batch',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'is_active'  => 'boolean',
        'tracks_batch' => 'boolean',
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
     * Produk "Premium Wash" — pola sama persis detailing(), dibuat lewat
     * migrasi 2026_09_22_000001 (SKU SVC-PREMIUM-WASH). Dipakai auto-isi
     * Memo Barang saat booking ber-product_premium_wash.
     */
    public static function premiumWash(): ?self
    {
        return static::where('product_type', 'premium_wash')->first();
    }

    /**
     * Master Resep (BOM) — bahan standar per 1x pemasangan produk ini.
     * Diminta 2026-09-10. Lihat FilmProductRecipeItem.
     */
    public function recipeItems()
    {
        return $this->hasMany(FilmProductRecipeItem::class);
    }

    /**
     * Harga jual per ukuran kendaraan (matriks). Harga flat = base_price.
     * Lihat FilmProductPrice & PriceCalculator (2026-09-10).
     */
    public function prices()
    {
        return $this->hasMany(FilmProductPrice::class);
    }

    /**
     * Harga khusus per grup pelanggan (audit Majoo f40) — lihat
     * FilmProductGroupPrice & PriceCalculator::priceFor().
     */
    public function groupPrices()
    {
        return $this->hasMany(FilmProductGroupPrice::class);
    }

    public function caseStudies()
    {
        return $this->hasMany(CaseStudy::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sku', 'name', 'product_type', 'position', 'base_price', 'is_active', 'tracks_batch'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('film_product')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Produk \"{$this->name}\" ({$this->sku}) ditambahkan",
                'updated' => "Produk \"{$this->name}\" ({$this->sku}) diubah",
                'deleted' => "Produk \"{$this->name}\" ({$this->sku}) dihapus",
                default => "Produk \"{$this->name}\" — {$eventName}",
            });
    }
}
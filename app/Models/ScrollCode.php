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
        'usage_count',
        'max_usage',
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

    /**
     * Sisi balik InventoryItem::scrollCode() — unit fisik gudang (kardus/
     * gulungan) yang dikaitkan ke kode ini, kalau sudah pernah didaftarkan
     * lewat menu Barang.
     */
    public function inventoryItem()
    {
        return $this->hasOne(InventoryItem::class);
    }

    /**
     * 1 kode gulungan sekarang bisa dipakai >1 warranty (PPF maupun
     * Window Film, sejak tidak lagi single-use otomatis) — kolom
     * warranty_code di tabel ini cuma menyimpan 1 nilai (warranty
     * TERAKHIR yang memakainya, gampang ketimpa), jadi TIDAK bisa
     * diandalkan untuk menampilkan semua pemakai. Query langsung ke
     * tabel warranties lewat 4 kolom roll_number* untuk dapat daftar
     * lengkapnya.
     */
    public function warranties()
    {
        return \App\Models\Warranty::query()
            ->where('roll_number', $this->code)
            ->orWhere('roll_number_2', $this->code)
            ->orWhere('roll_number_front', $this->code)
            ->orWhere('roll_number_side_rear', $this->code);
    }
}

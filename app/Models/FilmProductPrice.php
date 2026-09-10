<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Harga jual sebuah Produk Film untuk 1 ukuran kendaraan. Lihat migrasi
 * 2026_09_10_000005. Harga flat (sama semua ukuran) TIDAK di sini —
 * pakai FilmProduct.base_price.
 */
class FilmProductPrice extends Model
{
    use LogsActivity;

    protected $fillable = [
        'film_product_id',
        'vehicle_size',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function filmProduct(): BelongsTo
    {
        return $this->belongsTo(FilmProduct::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['film_product_id', 'vehicle_size', 'price'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('film_product_price');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Harga khusus 1 produk untuk 1 grup pelanggan — lihat migrasi
 * create_film_product_group_prices_table & PriceCalculator::priceFor().
 */
class FilmProductGroupPrice extends Model
{
    use LogsActivity;

    protected $fillable = [
        'film_product_id',
        'customer_group_id',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function filmProduct(): BelongsTo
    {
        return $this->belongsTo(FilmProduct::class);
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['film_product_id', 'customer_group_id', 'price'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('film_product_group_price');
    }
}

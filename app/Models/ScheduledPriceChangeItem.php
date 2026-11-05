<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu harga yang diubah oleh sebuah perubahan harga terjadwal: nilai sebelum dan sesudahnya. Append-only; dipakai tombol Kembalikan.
 */
class ScheduledPriceChangeItem extends Model
{
    /** Model harga yang boleh dipulihkan (daftar putih; model_type berasal dari database). */
    public const MODELS = [
        FilmProduct::class,
        FilmProductPrice::class,
        FilmProductGroupPrice::class,
    ];

    protected $fillable = ['scheduled_price_change_id', 'model_type', 'model_id', 'price_column', 'old_value', 'new_value', 'label'];

    protected $casts = [
        'old_value' => 'decimal:2',
        'new_value' => 'decimal:2',
    ];

    public function change(): BelongsTo
    {
        return $this->belongsTo(ScheduledPriceChange::class, 'scheduled_price_change_id');
    }
}

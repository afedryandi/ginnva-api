<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Produk TAMBAHAN per bagian kendaraan untuk 1 booking -- keputusan
 * atasan 2026-09-19 (Topik 3). Lihat catatan lengkap di migrasi
 * create_booking_film_products_table -- SENGAJA additif, bukan
 * pengganti `Booking::film_product_id` (produk utama).
 */
class BookingFilmProduct extends Model
{
    protected $fillable = [
        'booking_id',
        'film_product_id',
        'position',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function filmProduct(): BelongsTo
    {
        // withTrashed() (audit Daftar Produk 2026-09-25) -- lihat catatan
        // di Booking::filmProduct().
        return $this->belongsTo(FilmProduct::class)->withTrashed();
    }
}

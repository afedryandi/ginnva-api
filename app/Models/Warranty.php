<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Warranty extends Model
{
    protected $fillable = [
        'warranty_code',
        'customer_name',
        'phone_number',
        'car_plate',
        'car_type',
        'product_series',
        'installation_date',
        'expiry_date',
        'dealer_name',
        'store_id',
        'status',
    ];

    protected $casts = [
        'installation_date' => 'date',
        'expiry_date' => 'date',
    ];

    // Field tambahan yang otomatis ikut saat model di-convert ke JSON / array
    protected $appends = ['remaining_days'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * remaining_days TIDAK disimpan di kolom database — dihitung otomatis
     * setiap kali data diambil, supaya selalu akurat tanpa perlu update manual setiap hari.
     */
    public function getRemainingDaysAttribute(): int
    {
        $days = Carbon::now()->diffInDays($this->expiry_date, false);
        return max(0, (int) $days);
    }

    /**
     * Status otomatis disesuaikan kalau sudah lewat expiry_date,
     * supaya kolom `status` di database tidak perlu di-update manual oleh admin tiap hari.
     */
    public function getStatusAttribute($value): string
    {
        if ($this->expiry_date && Carbon::now()->greaterThan($this->expiry_date)) {
            return 'expired';
        }
        return $value;
    }
}
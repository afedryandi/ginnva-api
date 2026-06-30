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
        'customer_id',
        'status',
        'review_status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'installation_date' => 'date',
        'expiry_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    // Field tambahan yang otomatis ikut saat model di-convert ke JSON / array
    protected $appends = ['remaining_days'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Akun customer (mobile app) yang submit warranty ini, kalau ada.
     * Nullable — warranty yang disubmit sebagai guest (tanpa login)
     * tidak terhubung ke akun manapun, tapi tetap valid seperti biasa.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Riwayat klaim after-sales (Worry Free Wrap / Product Warranty /
     * Other). 1 warranty bisa punya banyak klaim sepanjang waktu.
     */
    public function claims()
    {
        return $this->hasMany(WarrantyClaim::class);
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
     * Status otomatis disesuaikan:
     * - Kalau review_status belum approved (masih pending_review atau
     *   rejected), warranty TIDAK dianggap aktif sama sekali — apapun
     *   nilai kolom `status` di database. Ini konsekuensi dari QA
     *   Certificate review: submission baru wajib di-approve dulu oleh
     *   super_admin sebelum garansi benar-benar berlaku.
     * - Kalau sudah approved tapi sudah lewat expiry_date -> expired.
     * - Selain itu, ikuti nilai kolom `status` apa adanya.
     */
    public function getStatusAttribute($value): string
    {
        if (($this->attributes['review_status'] ?? null) === 'pending_review') {
            return 'pending_review';
        }

        if (($this->attributes['review_status'] ?? null) === 'rejected') {
            return 'rejected';
        }

        if ($this->expiry_date && Carbon::now()->greaterThan($this->expiry_date)) {
            return 'expired';
        }

        return $value;
    }
}

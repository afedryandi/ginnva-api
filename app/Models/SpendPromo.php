<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Aturan "Promo Per Total Pembelian" — potongan flat Rp untuk booking
 * yang nominal (kotor) transaksinya >= min_purchase_amount. Diterapkan
 * manual oleh staf lewat form Booking. Lihat migrasi 2026_09_10_000014.
 */
class SpendPromo extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'description',
        'min_purchase_amount',
        'discount_amount',
        'starts_on',
        'ends_on',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'min_purchase_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Sedang berjalan: aktif + hari ini di dalam rentang tanggal
     * (tanggal kosong = tak terbatas di sisi itu).
     */
    public function isRunning(?Carbon $on = null): bool
    {
        $on = ($on ?? now())->startOfDay();

        return $this->is_active
            && ($this->starts_on === null || $on->gte($this->starts_on->startOfDay()))
            && ($this->ends_on === null || $on->lte($this->ends_on->startOfDay()));
    }

    public function scopeRunning($query)
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'min_purchase_amount', 'discount_amount', 'starts_on', 'ends_on', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('spend_promo');
    }
}

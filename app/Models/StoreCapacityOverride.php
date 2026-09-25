<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Override kapasitas instalasi 1 toko untuk 1 tanggal spesifik -- lihat
 * catatan lengkap di migrasi create_store_capacity_overrides_table.
 * Tidak ada baris = pakai Store::install_capacity_per_day apa adanya
 * (lihat Booking::capacityForDate()).
 */
class StoreCapacityOverride extends Model
{
    use LogsActivity;

    protected $fillable = [
        'store_id',
        'date',
        'capacity',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['store_id', 'date', 'capacity'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('store_capacity_override')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Kapasitas {$this->date?->format('d M Y')} diatur ke {$this->capacity}",
                'updated' => "Kapasitas {$this->date?->format('d M Y')} diubah jadi {$this->capacity}",
                'deleted' => "Override kapasitas {$this->date?->format('d M Y')} dihapus (kembali ke default)",
                default   => "Override kapasitas — {$eventName}",
            });
    }
}

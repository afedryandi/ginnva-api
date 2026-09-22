<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Tarif komisi per kombinasi teknisi + jenis layanan — lihat migrasi
 * create_technician_service_rates_table & Technician::commissionForBooking().
 */
class TechnicianServiceRate extends Model
{
    use LogsActivity;

    public const SERVICE_TYPE_LABELS = [
        'ppf' => 'PPF',
        'kaca_film' => 'Kaca Film',
        'detailing' => 'Detailing',
        'premium_wash' => 'Premium Wash',
    ];

    protected $fillable = [
        'technician_id',
        'service_type',
        'commission_amount',
    ];

    protected $casts = [
        'commission_amount' => 'decimal:2',
    ];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['service_type', 'commission_amount'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('technician_service_rate')
            ->setDescriptionForEvent(fn (string $eventName) => 'Tarif "' . (self::SERVICE_TYPE_LABELS[$this->service_type] ?? $this->service_type) . "\" untuk {$this->technician?->name} {$eventName}");
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Quotation extends Model
{
    use LogsActivity;

    protected $fillable = [
        'quotation_number',
        'vehicle_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'license_plate',
        'store_id',
        'status',
        'source',
        'message',
        'contacted_at',
    ];

    protected $casts = [
        'contacted_at' => 'datetime',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function items()
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'store_id', 'customer_name', 'customer_phone', 'customer_email'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('quotation')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Quotation #{$this->quotation_number} masuk",
                'updated' => "Quotation #{$this->quotation_number} diubah",
                'deleted' => "Quotation #{$this->quotation_number} dihapus",
                default   => "Quotation #{$this->quotation_number} — {$eventName}",
            });
    }
}
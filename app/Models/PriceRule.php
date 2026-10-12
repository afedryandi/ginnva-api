<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * LogsActivity ditambahkan 2026-10-12 (audit standar profesional): koefisien harga (ukuran kendaraan x bagian mobil)
 * menentukan harga penawaran, jadi perubahannya harus punya jejak siapa-mengubah-apa-kapan, sebelum/sesudah.
 */
class PriceRule extends Model
{
    use LogsActivity;

    protected $fillable = [
        'vehicle_size',
        'car_part',
        'coefficient',
    ];

    protected $casts = [
        'coefficient' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['vehicle_size', 'car_part', 'coefficient'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('price_rule')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Aturan harga {$this->vehicle_size} / {$this->car_part} ditambahkan",
                'updated' => "Aturan harga {$this->vehicle_size} / {$this->car_part} diubah",
                'deleted' => "Aturan harga {$this->vehicle_size} / {$this->car_part} dihapus",
                default   => "Aturan harga {$this->vehicle_size} / {$this->car_part} — {$eventName}",
            });
    }

    public function quotationItems()
    {
        return $this->hasMany(QuotationItem::class);
    }
}
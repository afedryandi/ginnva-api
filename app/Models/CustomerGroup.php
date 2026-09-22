<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * "Grup Pelanggan" (audit Majoo, f40) — master data untuk
 * mengelompokkan pelanggan (mis. Member/Korporat/Reseller) dan
 * memberi harga khusus per grup (lihat FilmProductGroupPrice).
 */
class CustomerGroup extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function groupPrices(): HasMany
    {
        return $this->hasMany(FilmProductGroupPrice::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'description', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('customer_group')
            ->setDescriptionForEvent(fn (string $eventName) => "Grup Pelanggan \"{$this->name}\" {$eventName}");
    }
}

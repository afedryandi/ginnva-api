<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RewardRedemption extends Model
{
    use LogsActivity;

    protected $fillable = [
        'redeemer_type',
        'redeemer_id',
        'reward_id',
        'points_spent',
        'status',
        'notes',
    ];

    protected $casts = [
        'points_spent' => 'integer',
    ];

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    /**
     * Cache statis per (type,id) diperbaiki 2026-09-26 (audit Katalog
     * Reward, gap N+1) -- redeemer_type/redeemer_id bukan morphTo Eloquent
     * standar (2 tabel tanpa base class sama), jadi tidak bisa
     * di-eager-load lewat ->with() biasa. Tabel Filament (RewardRedemptionResource)
     * memanggil redeemer() (lewat getRedeemerNameAttribute()) sekali per
     * baris -- cache ini setidaknya mencegah query DUPLIKAT kalau
     * customer/partner yang sama muncul di banyak baris pada 1 halaman
     * yang sama (kasus umum: 1 customer redeem reward berkali-kali).
     *
     * @var array<string, Partner|Customer|null>
     */
    private static array $redeemerCache = [];

    /**
     * Bukan morphTo Eloquent standar — redeemer_type cuma 'partner' atau
     * 'customer', dua tabel yang tidak share base class. Resolve manual.
     */
    public function redeemer(): Partner|Customer|null
    {
        $cacheKey = "{$this->redeemer_type}:{$this->redeemer_id}";

        if (array_key_exists($cacheKey, static::$redeemerCache)) {
            return static::$redeemerCache[$cacheKey];
        }

        return static::$redeemerCache[$cacheKey] = match ($this->redeemer_type) {
            'partner'  => Partner::find($this->redeemer_id),
            'customer' => Customer::find($this->redeemer_id),
            default    => null,
        };
    }

    // Dipakai di kolom tabel Filament — nama partner (business_name) atau
    // customer (name), tanpa perlu N+1 query manual di setiap baris.
    public function getRedeemerNameAttribute(): string
    {
        $redeemer = $this->redeemer();

        return match (true) {
            $redeemer instanceof Partner  => $redeemer->business_name . ' (Partner)',
            $redeemer instanceof Customer => $redeemer->name . ' (Customer)',
            default                       => '—',
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('reward_redemption')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Penukaran reward #{$this->id} dibuat ({$this->getRedeemerNameAttribute()})",
                'updated' => "Penukaran reward #{$this->id} diubah ({$this->getRedeemerNameAttribute()})",
                'deleted' => "Penukaran reward #{$this->id} dihapus",
                default   => "Penukaran reward #{$this->id} — {$eventName}",
            });
    }
}

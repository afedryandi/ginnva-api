<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardRedemption extends Model
{
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
     * Bukan morphTo Eloquent standar — redeemer_type cuma 'partner' atau
     * 'customer', dua tabel yang tidak share base class. Resolve manual.
     */
    public function redeemer(): Partner|Customer|null
    {
        return match ($this->redeemer_type) {
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
}

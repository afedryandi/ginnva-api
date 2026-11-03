<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'type',
        'note',
        'destination_store_id',
        'store_id',
        'user_id',
        'reversed_at',
        'reversed_by',
        'reversed_reason',
    ];

    protected $casts = [
        'reversed_at' => 'datetime',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function destinationStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'destination_store_id');
    }

    /** Toko akun yang mencatat (untuk Keluar bisa berbeda dari toko tujuan bila dicatat akses penuh). */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * Akses penuh melihat semua; staf lain hanya baris tokonya sendiri (toko pencatat atau toko tujuan). Akun tanpa toko dan bukan
     * akses penuh tidak melihat apa pun.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null || (! $user->isFullAccess() && $user->store_id === null)) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isFullAccess()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('inventory_movements.store_id', $user->store_id)
            ->orWhere('inventory_movements.destination_store_id', $user->store_id));
    }

    /** Pergerakan yang masih berlaku (belum dibatalkan). */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereNull('inventory_movements.reversed_at');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Boleh dibatalkan: bukan koreksi, belum dibatalkan, dan tidak ada pergerakan berlaku yang lebih baru untuk barang yang sama. */
    public function isReversible(): bool
    {
        if ($this->type === 'correction' || $this->reversed_at !== null || $this->inventory_item_id === null) {
            return false;
        }

        return ! static::query()
            ->where('inventory_item_id', $this->inventory_item_id)
            ->where('id', '>', $this->id)
            ->whereNull('reversed_at')
            ->where('type', '!=', 'correction')
            ->exists();
    }
}
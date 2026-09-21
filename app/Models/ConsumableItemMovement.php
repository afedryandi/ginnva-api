<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumableItemMovement extends Model
{
    protected $fillable = [
        'consumable_item_id',
        // Penanda cabang (Topik 4, Fase 1, 2026-09-19) -- OPSIONAL,
        // current_stock TETAP 1 angka nasional (lihat catatan lengkap di
        // migrasi add_store_id_to_material_movements_tables).
        'store_id',
        'type',
        'quantity',
        'unit_cost',
        'note',
        'user_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
    ];

    public function consumableItem(): BelongsTo
    {
        return $this->belongsTo(ConsumableItem::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
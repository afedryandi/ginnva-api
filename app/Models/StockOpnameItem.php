<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris per-item 1 sesi Stok Opname -- pola manual-morph sama dgn
 * StockWriteOff (item_type/item_id, tanpa FK). Lihat migrasi
 * create_stock_opname_items_table & StockOpnameService.
 */
class StockOpnameItem extends Model
{
    protected $fillable = [
        'stock_opname_id',
        'item_type',
        'item_id',
        'item_name',
        'unit',
        'system_quantity',
        'actual_quantity',
        'delta',
    ];

    protected $casts = [
        'system_quantity' => 'decimal:2',
        'actual_quantity' => 'decimal:2',
        'delta' => 'decimal:2',
    ];

    public function stockOpname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class);
    }

    /**
     * Sama pola dengan MaterialMemoItem::resolveItem() -- ambil model
     * asli dari manual-morph item_type/item_id.
     */
    public function resolveItem(): RawMaterial|ConsumableItem|null
    {
        return match ($this->item_type) {
            'raw_material' => RawMaterial::find($this->item_id),
            'consumable_item' => ConsumableItem::find($this->item_id),
            default => null,
        };
    }
}

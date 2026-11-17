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
        'note',
        'applied_system_quantity',
    ];

    protected $casts = [
        'system_quantity' => 'decimal:2',
        'actual_quantity' => 'decimal:2',
        'delta' => 'decimal:2',
        'applied_system_quantity' => 'decimal:2',
    ];

    /** Jenis item yang bisa dihitung. 'scroll_code' = gulungan PPF/WF (hasil hitung = sisa meter hasil ukur fisik). */
    public const TYPE_LABELS = [
        'raw_material' => 'Bahan Baku',
        'consumable_item' => 'Barang Habis Pakai',
        'scroll_code' => 'PPF/WF (gulungan)',
    ];

    public static function typeLabel(?string $type): string
    {
        return self::TYPE_LABELS[$type] ?? (string) $type;
    }

    public function stockOpname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class);
    }

    /**
     * Sama pola dengan MaterialMemoItem::resolveItem() -- ambil model
     * asli dari manual-morph item_type/item_id.
     */
    public function resolveItem(): RawMaterial|ConsumableItem|ScrollCode|null
    {
        return match ($this->item_type) {
            'raw_material' => RawMaterial::find($this->item_id),
            'consumable_item' => ConsumableItem::find($this->item_id),
            'scroll_code' => ScrollCode::find($this->item_id),
            default => null,
        };
    }

    /** Jumlah terkini item ini di sistem (stok, atau sisa meter untuk gulungan); null bila item sudah dihapus. */
    public function currentQuantity(): ?float
    {
        $item = $this->resolveItem();

        if ($item === null) {
            return null;
        }

        return (float) ($item instanceof ScrollCode ? $item->remaining_length_meters : $item->current_stock);
    }
}
